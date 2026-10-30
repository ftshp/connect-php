package main

import "strings"

// The list protoc-gen-php v36 prefixes; it differs from the PHP keyword list.
var reservedNames = map[string]bool{}

func init() {
	for _, name := range strings.Fields(`
		abstract and array as bool break callable case catch class clone const
		continue declare default die do echo else elseif empty enddeclare endfor
		endforeach endif endswitch endwhile eval exit extends false final finally
		float fn for foreach function global goto if implements include
		include_once instanceof insteadof int interface isset iterable list match
		mixed namespace never new null object or parent print private protected
		public readonly require require_once return self static string switch throw trait
		true try unset use var void while xor yield`) {
		reservedNames[name] = true
	}
}

func reservedPrefix(name, pkg string) string {
	if !reservedNames[strings.ToLower(name)] {
		return ""
	}
	if pkg == "google.protobuf" {
		return "GPB"
	}
	return "PB"
}

func phpNamespace(pkg string, override *string) string {
	if override != nil {
		return *override
	}
	if pkg == "" {
		return ""
	}
	segments := strings.Split(pkg, ".")
	for i, segment := range segments {
		if segment != "" && 'a' <= segment[0] && segment[0] <= 'z' {
			segment = strings.ToUpper(segment[:1]) + segment[1:]
		}
		segments[i] = reservedPrefix(segment, pkg) + segment
	}
	return strings.Join(segments, `\`)
}

// phpClass maps a message path (outermost first) to its class name relative to
// the file namespace. php_class_prefix replaces the reserved-name prefix.
func phpClass(path []string, classPrefix, pkg string) string {
	segments := make([]string, len(path))
	for i, name := range path {
		prefix := classPrefix
		if prefix == "" {
			prefix = reservedPrefix(name, pkg)
		}
		segments[i] = prefix + name
	}
	return strings.Join(segments, `\`)
}

func lcfirst(s string) string {
	if s == "" {
		return s
	}
	return strings.ToLower(s[:1]) + s[1:]
}
