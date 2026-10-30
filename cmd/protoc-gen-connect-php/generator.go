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
	"google.golang.org/protobuf/types/descriptorpb"
	"google.golang.org/protobuf/types/pluginpb"
)

//go:embed templates/*.tmpl
var templateFS embed.FS

var templates = template.Must(template.ParseFS(templateFS, "templates/*.tmpl"))

type serviceFile struct {
	Source    string
	Namespace string
	Service   string
	Name      string
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

func generate(req *pluginpb.CodeGeneratorRequest, stderr io.Writer) *pluginpb.CodeGeneratorResponse {
	resp := &pluginpb.CodeGeneratorResponse{
		SupportedFeatures: proto.Uint64(uint64(pluginpb.CodeGeneratorResponse_FEATURE_PROTO3_OPTIONAL)),
	}
	files, err := protodesc.NewFiles(&descriptorpb.FileDescriptorSet{File: req.ProtoFile})
	if err != nil {
		resp.Error = proto.String(err.Error())
		return resp
	}
	for _, name := range req.FileToGenerate {
		fd, err := files.FindFileByPath(name)
		if err != nil {
			resp.Error = proto.String(err.Error())
			return resp
		}
		for i := range fd.Services().Len() {
			generated, err := generateService(fd, fd.Services().Get(i), stderr)
			if err != nil {
				resp.Error = proto.String(err.Error())
				return resp
			}
			resp.File = append(resp.File, generated...)
		}
	}
	return resp
}

func generateService(fd protoreflect.FileDescriptor, sd protoreflect.ServiceDescriptor, stderr io.Writer) ([]*pluginpb.CodeGeneratorResponse_File, error) {
	data := serviceFile{
		Source:    fd.Path(),
		Namespace: fileNamespace(fd),
		Service:   string(sd.FullName()),
		Name:      string(sd.Name()),
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
	for _, md := range unary {
		opts := md.Options().(*descriptorpb.MethodOptions)
		m := method{
			Name:        string(md.Name()),
			PHPName:     lcfirst(string(md.Name())),
			Input:       refs[md.Input().FullName()],
			Output:      refs[md.Output().FullName()],
			Idempotency: idempotency(opts.GetIdempotencyLevel()),
			Doc:         docLines(fd.SourceLocations().ByDescriptor(md).LeadingComments, opts.GetDeprecated()),
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
		ns, short := splitClass(messageClass(md))
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
		class := messageClass(md)
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
	var override *string
	if opts := fd.Options().(*descriptorpb.FileOptions); opts != nil {
		override = opts.PhpNamespace
	}
	return phpNamespace(string(fd.Package()), override)
}

func messageClass(md protoreflect.MessageDescriptor) string {
	fd := md.ParentFile()
	var path []string
	for d := protoreflect.Descriptor(md); d != fd; d = d.Parent() {
		path = append([]string{string(d.Name())}, path...)
	}
	class := phpClass(path, fd.Options().(*descriptorpb.FileOptions).GetPhpClassPrefix(), string(fd.Package()))
	if ns := fileNamespace(fd); ns != "" {
		return ns + `\` + class
	}
	return class
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

func docLines(comment string, deprecated bool) []string {
	var lines []string
	for line := range strings.SplitSeq(strings.TrimRight(comment, " \n"), "\n") {
		line = strings.TrimRight(strings.TrimPrefix(line, " "), " \t")
		lines = append(lines, strings.ReplaceAll(line, "*/", `*\/`))
	}
	for len(lines) > 0 && lines[0] == "" {
		lines = lines[1:]
	}
	if len(lines) > 0 {
		lines = append(lines, "")
	}
	if deprecated {
		lines = append(lines, "@deprecated", "")
	}
	return append(lines, "@throws ConnectException")
}
