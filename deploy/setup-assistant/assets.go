package main

import "embed"

// Aset wizard (HTML) dan hasil build Tailwind/Alpine di-embed ke dalam binari
// agar server setup benar-benar mandiri (tanpa PHP/Node di PC tujuan).
//
//go:embed web/index.html
//go:embed web/dist
var webFS embed.FS
