package main

import (
	"bytes"
	"embed"
	"fmt"
	"io"
	"slices"
	"strings"
	"text/template"

	"google.golang.org/protobuf/proto"
	"google.golang.org/protobuf/reflect/protodesc"
	"google.golang.org/protobuf/reflect/protoreflect"
	"google.golang.org/protobuf/reflect/protoregistry"
	"google.golang.org/protobuf/types/descriptorpb"
	"google.golang.org/protobuf/types/pluginpb"
)

//go:embed templates/*.tmpl
var templateFS embed.FS

var templates = template.Must(template.ParseFS(templateFS, "templates/*.tmpl"))

type serviceFile struct {
	Version   string
	Source    string
	Namespace string
	Service   string
	Name      string
	Doc       []string
	Uses      []string
	Methods   []method
}

type method struct {
	Name        string
	PHPName     string
	Input       string
	Output      string
	Idempotency string
	Doc         []string
}

func generate(req *pluginpb.CodeGeneratorRequest, version string, stderr io.Writer) *pluginpb.CodeGeneratorResponse {
	resp := &pluginpb.CodeGeneratorResponse{
		SupportedFeatures: proto.Uint64(uint64(pluginpb.CodeGeneratorResponse_FEATURE_PROTO3_OPTIONAL | pluginpb.CodeGeneratorResponse_FEATURE_SUPPORTS_EDITIONS)),
		MinimumEdition:    proto.Int32(int32(descriptorpb.Edition_EDITION_PROTO2)),
		MaximumEdition:    proto.Int32(int32(descriptorpb.Edition_EDITION_2024)),
	}
	files, err := protodesc.NewFiles(&descriptorpb.FileDescriptorSet{File: req.ProtoFile})
	if err != nil {
		resp.Error = proto.String(err.Error())
		return resp
	}
	classes := typeClasses(files)
	for _, name := range req.FileToGenerate {
		fd, err := files.FindFileByPath(name)
		if err != nil {
			resp.Error = proto.String(err.Error())
			return resp
		}
		for i := range fd.Services().Len() {
			generated, err := generateService(fd, fd.Services().Get(i), version, classes, stderr)
			if err != nil {
				resp.Error = proto.String(err.Error())
				return resp
			}
			resp.File = append(resp.File, generated...)
		}
	}
	return resp
}

// typeClasses maps the lower-cased class name of every message and enum in
// the request to a description of its proto type. PHP class names are
// case-insensitive.
func typeClasses(files *protoregistry.Files) map[string]string {
	classes := map[string]string{}
	var walk func(messages protoreflect.MessageDescriptors, enums protoreflect.EnumDescriptors)
	walk = func(messages protoreflect.MessageDescriptors, enums protoreflect.EnumDescriptors) {
		for i := range enums.Len() {
			classes[strings.ToLower(typeClass(enums.Get(i)))] = "enum " + string(enums.Get(i).FullName())
		}
		for i := range messages.Len() {
			md := messages.Get(i)
			if md.IsMapEntry() {
				continue
			}
			classes[strings.ToLower(typeClass(md))] = "message " + string(md.FullName())
			walk(md.Messages(), md.Enums())
		}
	}
	files.RangeFiles(func(fd protoreflect.FileDescriptor) bool {
		walk(fd.Messages(), fd.Enums())
		return true
	})
	return classes
}

func generateService(fd protoreflect.FileDescriptor, sd protoreflect.ServiceDescriptor, version string, classes map[string]string, stderr io.Writer) ([]*pluginpb.CodeGeneratorResponse_File, error) {
	data := serviceFile{
		Version:   version,
		Source:    fd.Path(),
		Namespace: fileNamespace(fd),
		Service:   string(sd.FullName()),
		Name:      string(sd.Name()),
		Doc:       docLines(fd.SourceLocations().ByDescriptor(sd).LeadingComments, sd.Options().(*descriptorpb.ServiceOptions).GetDeprecated()),
	}
	for _, suffix := range []string{"Interface", "Client"} {
		class := qualify(data.Namespace, data.Name+suffix)
		key := strings.ToLower(class)
		if owner, ok := classes[key]; ok {
			return nil, fmt.Errorf("%s: class %s for the service collides with %s; rename one of them", sd.FullName(), class, owner)
		}
		classes[key] = "service " + string(sd.FullName())
	}
	var unary []protoreflect.MethodDescriptor
	var messages []protoreflect.MessageDescriptor
	for i := range sd.Methods().Len() {
		md := sd.Methods().Get(i)
		if md.IsStreamingClient() || md.IsStreamingServer() {
			fmt.Fprintf(stderr, "protoc-gen-connect-php: skipping %s/%s: streaming RPCs are not supported\n", sd.FullName(), md.Name())
			continue
		}
		unary = append(unary, md)
		messages = append(messages, md.Input(), md.Output())
	}
	taken := map[string]bool{}
	for _, name := range []string{"Client", "ConnectException", "Context", "Idempotency", "MethodDescriptor", "Procedure", "Service", data.Name + "Interface", data.Name + "Client"} {
		taken[strings.ToLower(name)] = true
	}
	refs, imports := resolveClasses(data.Namespace, messages, taken)

	idempotent := false
	owners := map[string]string{}
	for _, md := range unary {
		name := lcfirst(string(md.Name()))
		if strings.HasPrefix(name, "__") {
			return nil, fmt.Errorf("%s/%s: PHP reserves method names starting with __ for magic methods; rename the RPC", sd.FullName(), md.Name())
		}
		key := strings.ToLower(name)
		if other, ok := owners[key]; ok {
			return nil, fmt.Errorf("%s: RPCs %s and %s map to the same PHP method name %s", sd.FullName(), other, md.Name(), name)
		}
		owners[key] = string(md.Name())
		opts := md.Options().(*descriptorpb.MethodOptions)
		m := method{
			Name:        string(md.Name()),
			PHPName:     name,
			Input:       refs[md.Input().FullName()],
			Output:      refs[md.Output().FullName()],
			Idempotency: idempotency(opts.GetIdempotencyLevel()),
			Doc:         docLines(fd.SourceLocations().ByDescriptor(md).LeadingComments, opts.GetDeprecated(), "@throws ConnectException"),
		}
		idempotent = idempotent || m.Idempotency != "Unknown"
		data.Methods = append(data.Methods, m)
	}

	interfaceUses := []string{`Ftshp\Connect\Attribute\Service`}
	var clientUses []string
	if len(data.Methods) > 0 {
		interfaceUses = append(interfaceUses, `Ftshp\Connect\Attribute\Procedure`, `Ftshp\Connect\ConnectException`, `Ftshp\Connect\Context`)
		clientUses = []string{`Ftshp\Connect\Client`, `Ftshp\Connect\Context`, `Ftshp\Connect\Idempotency`, `Ftshp\Connect\MethodDescriptor`}
	}
	if idempotent {
		interfaceUses = append(interfaceUses, `Ftshp\Connect\Idempotency`)
	}

	dir := strings.ReplaceAll(data.Namespace, `\`, "/")
	if dir != "" {
		dir += "/"
	}
	var out []*pluginpb.CodeGeneratorResponse_File
	for _, kind := range []struct {
		suffix string
		uses   []string
	}{{"Interface", interfaceUses}, {"Client", clientUses}} {
		data.Uses = sortedUses(slices.Concat(kind.uses, imports))
		var buf bytes.Buffer
		if err := templates.ExecuteTemplate(&buf, strings.ToLower(kind.suffix)+".php.tmpl", data); err != nil {
			return nil, err
		}
		out = append(out, &pluginpb.CodeGeneratorResponse_File{
			Name:    proto.String(dir + data.Name + kind.suffix + ".php"),
			Content: proto.String(buf.String()),
		})
	}
	return out, nil
}

// resolveClasses picks how each message class is written: a short name when it
// is in the file namespace or can be imported, else fully qualified. Short
// names in the file namespace are reserved first because an import would
// shadow them.
func resolveClasses(namespace string, messages []protoreflect.MessageDescriptor, taken map[string]bool) (map[protoreflect.FullName]string, []string) {
	refs := map[protoreflect.FullName]string{}
	var imports []string
	for _, md := range messages {
		ns, short := splitClass(typeClass(md))
		if ns == namespace && !taken[strings.ToLower(short)] {
			refs[md.FullName()] = short
		}
	}
	for _, ref := range refs {
		taken[strings.ToLower(ref)] = true
	}
	imported := map[string]string{}
	for _, md := range messages {
		if _, ok := refs[md.FullName()]; ok {
			continue
		}
		class := typeClass(md)
		ns, short := splitClass(class)
		key := strings.ToLower(short)
		switch {
		case ns != namespace && imported[key] == class:
			refs[md.FullName()] = short
		case ns != namespace && !taken[key]:
			taken[key] = true
			imported[key] = class
			imports = append(imports, class)
			refs[md.FullName()] = short
		default:
			refs[md.FullName()] = `\` + class
		}
	}
	return refs, imports
}

func fileNamespace(fd protoreflect.FileDescriptor) string {
	if fd.Path() == "google/protobuf/descriptor.proto" {
		return `Google\Protobuf\Internal`
	}
	var override *string
	if opts := fd.Options().(*descriptorpb.FileOptions); opts != nil {
		override = opts.PhpNamespace
	}
	return phpNamespace(string(fd.Package()), override)
}

// typeClass returns the class protoc-gen-php writes for a message or enum.
func typeClass(desc protoreflect.Descriptor) string {
	fd := desc.ParentFile()
	var path []string
	for d := desc; d != fd; d = d.Parent() {
		path = append([]string{string(d.Name())}, path...)
	}
	return qualify(fileNamespace(fd), phpClass(path, fd.Options().(*descriptorpb.FileOptions).GetPhpClassPrefix(), string(fd.Package())))
}

func qualify(namespace, class string) string {
	if namespace == "" {
		return class
	}
	return namespace + `\` + class
}

func splitClass(class string) (namespace, short string) {
	i := strings.LastIndex(class, `\`)
	if i < 0 {
		return "", class
	}
	return class[:i], class[i+1:]
}

func sortedUses(uses []string) []string {
	slices.SortFunc(uses, func(a, b string) int {
		return strings.Compare(strings.ToLower(a), strings.ToLower(b))
	})
	return uses
}

func idempotency(level descriptorpb.MethodOptions_IdempotencyLevel) string {
	switch level {
	case descriptorpb.MethodOptions_NO_SIDE_EFFECTS:
		return "NoSideEffects"
	case descriptorpb.MethodOptions_IDEMPOTENT:
		return "Idempotent"
	}
	return "Unknown"
}

// docLines turns a proto comment into docblock lines. "@" and "*/" are
// escaped the way protoc-gen-php escapes them, so a comment never adds a
// PHPDoc tag or ends the docblock.
func docLines(comment string, deprecated bool, tags ...string) []string {
	var lines []string
	for line := range strings.SplitSeq(strings.TrimRight(comment, " \n"), "\n") {
		line = strings.TrimRight(strings.TrimPrefix(line, " "), " \t")
		lines = append(lines, phpdocEscaper.Replace(line))
	}
	for len(lines) > 0 && lines[0] == "" {
		lines = lines[1:]
	}
	if deprecated {
		tags = append([]string{"@deprecated"}, tags...)
	}
	for _, tag := range tags {
		if len(lines) > 0 {
			lines = append(lines, "")
		}
		lines = append(lines, tag)
	}
	return lines
}

var phpdocEscaper = strings.NewReplacer("*/", "{@*}", "@", `\@`)
