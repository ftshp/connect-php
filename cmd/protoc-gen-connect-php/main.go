package main

import (
	"flag"
	"fmt"
	"io"
	"os"
	"runtime/debug"
	"strings"

	"google.golang.org/protobuf/proto"
	"google.golang.org/protobuf/types/pluginpb"
)

var version = "devel"

func main() {
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()
	if *showVersion {
		fmt.Println("protoc-gen-connect-php", buildVersion())
		return
	}
	if err := run(os.Stdin, os.Stdout, os.Stderr); err != nil {
		fmt.Fprintln(os.Stderr, "protoc-gen-connect-php:", err)
		os.Exit(1)
	}
}

func buildVersion() string {
	if version != "devel" {
		return version
	}
	if info, ok := debug.ReadBuildInfo(); ok && info.Main.Version != "" && info.Main.Version != "(devel)" {
		return strings.TrimPrefix(info.Main.Version, "v")
	}
	return version
}

func run(in io.Reader, out, stderr io.Writer) error {
	raw, err := io.ReadAll(in)
	if err != nil {
		return err
	}
	var req pluginpb.CodeGeneratorRequest
	if err := proto.Unmarshal(raw, &req); err != nil {
		return err
	}
	if req.GetParameter() != "" {
		return fmt.Errorf("unknown option %q", req.GetParameter())
	}
	raw, err = proto.Marshal(generate(&req, stderr))
	if err != nil {
		return err
	}
	_, err = out.Write(raw)
	return err
}
