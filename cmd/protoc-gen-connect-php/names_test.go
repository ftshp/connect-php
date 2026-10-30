package main

import "testing"

func TestPHPNamespace(t *testing.T) {
	empty := ""
	billing := `Acme\Billing`
	tests := []struct {
		pkg      string
		override *string
		want     string
	}{
		{"ftshp.connect.test", nil, `Ftshp\Connect\Test`},
		{"acme.service.text_to_speech", nil, `Acme\Service\Text_to_speech`},
		{"google.protobuf", nil, `Google\Protobuf`},
		{"bar.list.x9a", nil, `Bar\PBList\X9a`},
		{"acme.billing.v1", &billing, `Acme\Billing`},
		{"baz", &empty, ""},
		{"", nil, ""},
	}
	for _, tt := range tests {
		if got := phpNamespace(tt.pkg, tt.override); got != tt.want {
			t.Errorf("phpNamespace(%q) = %q, want %q", tt.pkg, got, tt.want)
		}
	}
}

func TestPHPClass(t *testing.T) {
	tests := []struct {
		path   []string
		prefix string
		pkg    string
		want   string
	}{
		{[]string{"EchoRequest"}, "", "ftshp.connect.test", "EchoRequest"},
		{[]string{"Empty"}, "", "ftshp.connect.test", "PBEmpty"},
		{[]string{"Class"}, "", "acme", "PBClass"},
		{[]string{"readonly"}, "", "acme", "PBreadonly"},
		{[]string{"Enum"}, "", "acme", "Enum"},
		{[]string{"Empty"}, "", "google.protobuf", "GPBEmpty"},
		{[]string{"Outer", "Inner"}, "", "acme", `Outer\Inner`},
		{[]string{"Class", "Inner"}, "", "acme", `PBClass\Inner`},
		{[]string{"Invoice"}, "PB", "acme.billing", "PBInvoice"},
		{[]string{"Class", "List"}, "XY", "acme", `XYClass\XYList`},
	}
	for _, tt := range tests {
		if got := phpClass(tt.path, tt.prefix, tt.pkg); got != tt.want {
			t.Errorf("phpClass(%q, %q) = %q, want %q", tt.path, tt.prefix, got, tt.want)
		}
	}
}

func TestLcfirst(t *testing.T) {
	for in, want := range map[string]string{"AvailabilityGet": "availabilityGet", "WMSCheck": "wMSCheck", "echo": "echo", "": ""} {
		if got := lcfirst(in); got != want {
			t.Errorf("lcfirst(%q) = %q, want %q", in, got, want)
		}
	}
}
