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
	}
	for name, wantStderr := range cases {
		t.Run(name, func(t *testing.T) {
			dir := filepath.Join("testdata", name)
			req := compile(t, protoc, dir)
			var stderr bytes.Buffer
			resp := generate(req, &stderr)
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

func serviceRequest(methods ...string) *pluginpb.CodeGeneratorRequest {
	svc := &descriptorpb.ServiceDescriptorProto{Name: proto.String("Svc")}
	for _, name := range methods {
		svc.Method = append(svc.Method, &descriptorpb.MethodDescriptorProto{
			Name:       proto.String(name),
			InputType:  proto.String(".acme.Msg"),
			OutputType: proto.String(".acme.Msg"),
		})
	}
	file := &descriptorpb.FileDescriptorProto{
		Name:        proto.String("acme/svc.proto"),
		Package:     proto.String("acme"),
		Syntax:      proto.String("proto3"),
		MessageType: []*descriptorpb.DescriptorProto{{Name: proto.String("Msg")}},
		Service:     []*descriptorpb.ServiceDescriptorProto{svc},
	}
	return &pluginpb.CodeGeneratorRequest{FileToGenerate: []string{"acme/svc.proto"}, ProtoFile: []*descriptorpb.FileDescriptorProto{file}}
}

func TestGenerateRejectsCollidingRPCNames(t *testing.T) {
	cases := map[string][]string{
		"method suffix": {"Get", "GetMethod"},
		"case":          {"Foo", "foo"},
	}
	for name, methods := range cases {
		t.Run(name, func(t *testing.T) {
			resp := generate(serviceRequest(methods...), &bytes.Buffer{})
			for _, want := range []string{"acme.Svc", methods[0], methods[1]} {
				if !strings.Contains(resp.GetError(), want) {
					t.Errorf("error = %q, want it to mention %q", resp.GetError(), want)
				}
			}
		})
	}
}

func TestGenerateAcceptsDistinctRPCNames(t *testing.T) {
	if resp := generate(serviceRequest("Get", "List"), &bytes.Buffer{}); resp.Error != nil {
		t.Fatal(resp.GetError())
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
	got := messageClass((&descriptorpb.FileDescriptorSet{}).ProtoReflect().Descriptor())
	if want := `Google\Protobuf\Internal\FileDescriptorSet`; got != want {
		t.Errorf("class = %q, want %q", got, want)
	}
}
