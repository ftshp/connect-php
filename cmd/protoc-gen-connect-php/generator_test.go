package main

import (
	"bytes"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"google.golang.org/protobuf/proto"
	"google.golang.org/protobuf/reflect/protodesc"
	"google.golang.org/protobuf/types/descriptorpb"
	"google.golang.org/protobuf/types/pluginpb"
)

func TestGolden(t *testing.T) {
	protoc, err := exec.LookPath("protoc")
	if err != nil {
		t.Skip("protoc is not on PATH")
	}
	cases := map[string]string{
		"echo":      "protoc-gen-connect-php: skipping ftshp.connect.test.Echo/Watch: streaming RPCs are not supported\n",
		"namespace": "",
		"names":     "",
		"docs":      "",
	}
	for name, wantStderr := range cases {
		t.Run(name, func(t *testing.T) {
			dir := filepath.Join("testdata", name)
			req := compile(t, protoc, dir)
			var stderr bytes.Buffer
			resp := generate(req, "", &stderr)
			if resp.Error != nil {
				t.Fatal(resp.GetError())
			}
			if stderr.String() != wantStderr {
				t.Errorf("stderr = %q, want %q", stderr.String(), wantStderr)
			}
			assertFiles(t, filepath.Join(dir, "expected"), resp.File)
		})
	}
}

func compile(t *testing.T, protoc, dir string) *pluginpb.CodeGeneratorRequest {
	t.Helper()
	var files []string
	err := filepath.WalkDir(dir, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if strings.HasSuffix(path, ".proto") {
			rel, _ := filepath.Rel(dir, path)
			files = append(files, filepath.ToSlash(rel))
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	out := filepath.Join(t.TempDir(), "set.pb")
	args := append([]string{"-I" + dir, "--include_imports", "--include_source_info", "--descriptor_set_out=" + out}, files...)
	if msg, err := exec.Command(protoc, args...).CombinedOutput(); err != nil {
		t.Fatalf("protoc: %v\n%s", err, msg)
	}
	raw, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	var set descriptorpb.FileDescriptorSet
	if err := proto.Unmarshal(raw, &set); err != nil {
		t.Fatal(err)
	}
	return &pluginpb.CodeGeneratorRequest{FileToGenerate: files, ProtoFile: set.File}
}

func assertFiles(t *testing.T, expectedDir string, got []*pluginpb.CodeGeneratorResponse_File) {
	t.Helper()
	produced := map[string]string{}
	for _, f := range got {
		produced[f.GetName()] = f.GetContent()
	}
	err := filepath.WalkDir(expectedDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		rel, _ := filepath.Rel(expectedDir, path)
		rel = filepath.ToSlash(rel)
		want, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		content, ok := produced[rel]
		if !ok {
			t.Errorf("%s was not generated", rel)
			return nil
		}
		delete(produced, rel)
		if content != string(want) {
			t.Errorf("%s differs:\n--- got\n%s\n--- want\n%s", rel, content, want)
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	for name := range produced {
		t.Errorf("unexpected file %s", name)
	}
}

func protoFile(name, pkg string, messages, enums []string, services ...*descriptorpb.ServiceDescriptorProto) *descriptorpb.FileDescriptorProto {
	file := &descriptorpb.FileDescriptorProto{
		Name:    proto.String(name),
		Package: proto.String(pkg),
		Syntax:  proto.String("proto3"),
		Service: services,
	}
	for _, m := range messages {
		file.MessageType = append(file.MessageType, &descriptorpb.DescriptorProto{Name: proto.String(m)})
	}
	for _, e := range enums {
		file.EnumType = append(file.EnumType, &descriptorpb.EnumDescriptorProto{
			Name:  proto.String(e),
			Value: []*descriptorpb.EnumValueDescriptorProto{{Name: proto.String(strings.ToUpper(e) + "_UNSPECIFIED"), Number: proto.Int32(0)}},
		})
	}
	return file
}

func service(name string, methods ...string) *descriptorpb.ServiceDescriptorProto {
	svc := &descriptorpb.ServiceDescriptorProto{Name: proto.String(name)}
	for _, m := range methods {
		svc.Method = append(svc.Method, &descriptorpb.MethodDescriptorProto{
			Name:       proto.String(m),
			InputType:  proto.String(".acme.Msg"),
			OutputType: proto.String(".acme.Msg"),
		})
	}
	return svc
}

func serviceRequest(methods ...string) *pluginpb.CodeGeneratorRequest {
	file := protoFile("acme/svc.proto", "acme", []string{"Msg"}, nil, service("Svc", methods...))
	return &pluginpb.CodeGeneratorRequest{FileToGenerate: []string{"acme/svc.proto"}, ProtoFile: []*descriptorpb.FileDescriptorProto{file}}
}

func assertError(t *testing.T, resp *pluginpb.CodeGeneratorResponse, want ...string) {
	t.Helper()
	if resp.Error == nil {
		t.Fatal("generation succeeded, want an error")
	}
	for _, w := range want {
		if !strings.Contains(resp.GetError(), w) {
			t.Errorf("error = %q, want it to mention %q", resp.GetError(), w)
		}
	}
}

func TestGenerateRejectsCollidingRPCNames(t *testing.T) {
	assertError(t, generate(serviceRequest("Foo", "foo"), "", &bytes.Buffer{}), "acme.Svc", "Foo", "foo")
}

func TestGenerateAcceptsDistinctRPCNames(t *testing.T) {
	for _, methods := range [][]string{{"Get", "List"}, {"Get", "GetMethod"}} {
		if resp := generate(serviceRequest(methods...), "", &bytes.Buffer{}); resp.Error != nil {
			t.Errorf("%v: %s", methods, resp.GetError())
		}
	}
}

func TestGenerateRejectsMagicMethodNames(t *testing.T) {
	for _, name := range []string{"__construct", "__toString", "__Call", "__x"} {
		t.Run(name, func(t *testing.T) {
			assertError(t, generate(serviceRequest("Get", name), "", &bytes.Buffer{}), "acme.Svc/"+name, "__")
		})
	}
}

func TestGenerateRejectsClassCollisions(t *testing.T) {
	cases := map[string]struct {
		files []*descriptorpb.FileDescriptorProto
		want  []string
	}{
		"message": {
			files: []*descriptorpb.FileDescriptorProto{protoFile("acme/svc.proto", "acme", []string{"Msg", "SvcClient"}, nil, service("Svc", "Get"))},
			want:  []string{"acme.Svc", `Acme\SvcClient`, "message acme.SvcClient"},
		},
		"enum": {
			files: []*descriptorpb.FileDescriptorProto{protoFile("acme/svc.proto", "acme", []string{"Msg"}, []string{"SvcInterface"}, service("Svc", "Get"))},
			want:  []string{"acme.Svc", `Acme\SvcInterface`, "enum acme.SvcInterface"},
		},
		"case": {
			files: []*descriptorpb.FileDescriptorProto{protoFile("acme/svc.proto", "acme", []string{"Msg", "Svcclient"}, nil, service("Svc", "Get"))},
			want:  []string{"acme.Svc", `Acme\SvcClient`, "message acme.Svcclient"},
		},
		"other file": {
			files: []*descriptorpb.FileDescriptorProto{
				protoFile("acme/svc.proto", "acme", []string{"Msg"}, nil, service("Svc", "Get")),
				protoFile("acme/types.proto", "acme", []string{"SvcClient"}, nil),
			},
			want: []string{"acme.Svc", `Acme\SvcClient`, "message acme.SvcClient"},
		},
		"nested message": {
			files: []*descriptorpb.FileDescriptorProto{
				withNested(protoFile("acme/types.proto", "acme", []string{"Msg", "Outer"}, nil), "SvcClient"),
				withDependency(protoFile("acme/outer/svc.proto", "acme.outer", nil, nil, service("Svc", "Get")), "acme/types.proto"),
			},
			want: []string{"acme.outer.Svc", `Acme\Outer\SvcClient`, "message acme.Outer.SvcClient"},
		},
		"service": {
			files: []*descriptorpb.FileDescriptorProto{
				protoFile("acme/svc.proto", "acme", []string{"Msg"}, nil, service("Svc", "Get")),
				withDependency(protoFile("acme/other.proto", "acme", nil, nil, service("svc", "Get")), "acme/svc.proto"),
			},
			want: []string{"acme.svc", `Acme\svcInterface`, "service acme.Svc"},
		},
	}
	for name, tt := range cases {
		t.Run(name, func(t *testing.T) {
			req := &pluginpb.CodeGeneratorRequest{ProtoFile: tt.files}
			for _, f := range tt.files {
				req.FileToGenerate = append(req.FileToGenerate, f.GetName())
			}
			assertError(t, generate(req, "", &bytes.Buffer{}), tt.want...)
		})
	}
}

func withNested(file *descriptorpb.FileDescriptorProto, names ...string) *descriptorpb.FileDescriptorProto {
	outer := file.MessageType[len(file.MessageType)-1]
	for _, n := range names {
		outer.NestedType = append(outer.NestedType, &descriptorpb.DescriptorProto{Name: proto.String(n)})
	}
	return file
}

func TestTypeClassesSkipsMapEntries(t *testing.T) {
	file := protoFile("acme/svc.proto", "acme", []string{"Msg"}, nil)
	file.MessageType[0].Field = []*descriptorpb.FieldDescriptorProto{{
		Name:     proto.String("labels"),
		Number:   proto.Int32(1),
		Label:    descriptorpb.FieldDescriptorProto_LABEL_REPEATED.Enum(),
		Type:     descriptorpb.FieldDescriptorProto_TYPE_MESSAGE.Enum(),
		TypeName: proto.String(".acme.Msg.LabelsEntry"),
		JsonName: proto.String("labels"),
	}}
	file.MessageType[0].NestedType = []*descriptorpb.DescriptorProto{{
		Name:    proto.String("LabelsEntry"),
		Options: &descriptorpb.MessageOptions{MapEntry: proto.Bool(true)},
		Field: []*descriptorpb.FieldDescriptorProto{
			{Name: proto.String("key"), Number: proto.Int32(1), Label: descriptorpb.FieldDescriptorProto_LABEL_OPTIONAL.Enum(), Type: descriptorpb.FieldDescriptorProto_TYPE_STRING.Enum(), JsonName: proto.String("key")},
			{Name: proto.String("value"), Number: proto.Int32(2), Label: descriptorpb.FieldDescriptorProto_LABEL_OPTIONAL.Enum(), Type: descriptorpb.FieldDescriptorProto_TYPE_STRING.Enum(), JsonName: proto.String("value")},
		},
	}}
	files, err := protodesc.NewFiles(&descriptorpb.FileDescriptorSet{File: []*descriptorpb.FileDescriptorProto{file}})
	if err != nil {
		t.Fatal(err)
	}
	classes := typeClasses(files)
	if _, ok := classes[`acme\msg\labelsentry`]; ok {
		t.Errorf("map entry is listed: %v", classes)
	}
	if _, ok := classes[`acme\msg`]; !ok {
		t.Errorf("message is missing: %v", classes)
	}
}

func withDependency(file *descriptorpb.FileDescriptorProto, deps ...string) *descriptorpb.FileDescriptorProto {
	file.Dependency = deps
	return file
}

func TestGenerateAcceptsSameClassNameInOtherNamespace(t *testing.T) {
	req := serviceRequest("Get")
	req.ProtoFile = append(req.ProtoFile, protoFile("acme/other/types.proto", "acme.other", []string{"SvcClient", "SvcInterface"}, nil))
	if resp := generate(req, "", &bytes.Buffer{}); resp.Error != nil {
		t.Fatal(resp.GetError())
	}
}

func TestGenerateSupportsEditions(t *testing.T) {
	for _, edition := range []descriptorpb.Edition{descriptorpb.Edition_EDITION_2023, descriptorpb.Edition_EDITION_2024} {
		req := serviceRequest("Get")
		req.ProtoFile[0].Syntax = proto.String("editions")
		req.ProtoFile[0].Edition = edition.Enum()
		resp := generate(req, "", &bytes.Buffer{})
		if resp.Error != nil {
			t.Fatalf("%v: %s", edition, resp.GetError())
		}
		if len(resp.File) != 2 {
			t.Errorf("%v: generated %d files, want 2", edition, len(resp.File))
		}
	}
}

func TestGenerateDeclaresEditionRange(t *testing.T) {
	resp := generate(serviceRequest("Get"), "", &bytes.Buffer{})
	if resp.GetSupportedFeatures()&uint64(pluginpb.CodeGeneratorResponse_FEATURE_SUPPORTS_EDITIONS) == 0 {
		t.Error("FEATURE_SUPPORTS_EDITIONS is not declared")
	}
	if got := descriptorpb.Edition(resp.GetMinimumEdition()); got != descriptorpb.Edition_EDITION_PROTO2 {
		t.Errorf("minimum edition = %v", got)
	}
	if got := descriptorpb.Edition(resp.GetMaximumEdition()); got != descriptorpb.Edition_EDITION_2024 {
		t.Errorf("maximum edition = %v", got)
	}
}

func TestGenerateWritesVersionInHeader(t *testing.T) {
	for version, want := range map[string]string{
		"v0.2.0": "DO NOT EDIT.\n// versions: protoc-gen-connect-php v0.2.0\n// source: acme/svc.proto\n",
		"":       "DO NOT EDIT.\n// source: acme/svc.proto\n",
	} {
		resp := generate(serviceRequest("Get"), version, &bytes.Buffer{})
		for _, f := range resp.File {
			if !strings.Contains(f.GetContent(), want) {
				t.Errorf("%s with version %q:\n%s", f.GetName(), version, f.GetContent())
			}
		}
	}
}

func TestHeaderVersion(t *testing.T) {
	for in, want := range map[string]string{
		"0.2.0":                               "v0.2.0",
		"1.0.0-rc.1":                          "v1.0.0-rc.1",
		"devel":                               "",
		"":                                    "",
		"0.2.0+dirty":                         "",
		"0.1.1-0.20261102153248-ca019e953ce8": "",
		"0.0.0-20261102153248-ca019e953ce8":   "",
		"1.0.0-rc.1.0.20261102153248-ca019e953ce8":  "",
		"0.1.1-0.20261102153248-ca019e953ce8+dirty": "",
	} {
		if got := headerVersion(in); got != want {
			t.Errorf("headerVersion(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRunRejectsUnknownOption(t *testing.T) {
	raw, err := proto.Marshal(&pluginpb.CodeGeneratorRequest{Parameter: proto.String("foo=bar")})
	if err != nil {
		t.Fatal(err)
	}
	err = run(bytes.NewReader(raw), &bytes.Buffer{}, &bytes.Buffer{})
	if err == nil || err.Error() != `unknown option "foo=bar"` {
		t.Errorf("err = %v", err)
	}
}

func TestDescriptorProtoNamespace(t *testing.T) {
	got := typeClass((&descriptorpb.FileDescriptorSet{}).ProtoReflect().Descriptor())
	if want := `Google\Protobuf\Internal\FileDescriptorSet`; got != want {
		t.Errorf("class = %q, want %q", got, want)
	}
}
