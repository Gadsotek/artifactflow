# Third-party notices

This file records direct third-party components whose binaries or bundled
browser code are distributed by ArtifactFlow. It complements, rather than
replaces, the dependency manifests and generated software bills of materials.

## Browser application support

### Khrôma 2.1.0

Mermaid transitively bundles Khrôma 2.1.0 for CSS color and theme
calculations. It is distributed under the MIT License:

> Copyright (c) 2019-present Fabio Spampinato, Andrew Maney
>
> Permission is hereby granted, free of charge, to any person obtaining a
> copy of this software and associated documentation files (the "Software"),
> to deal in the Software without restriction, including without limitation
> the rights to use, copy, modify, merge, publish, distribute, sublicense,
> and/or sell copies of the Software, and to permit persons to whom the
> Software is furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in
> all copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
> FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
> DEALINGS IN THE SOFTWARE.

License source: <https://github.com/fabiospampinato/khroma/blob/v2.1.0/license>

## Office artifact support

### SheetJS Community Edition 0.20.3

The XLSX processor uses SheetJS Community Edition 0.20.3, Copyright (C)
2012-present SheetJS LLC, under the Apache License, Version 2.0. The complete
license text remains in the installed package at
`node_modules/xlsx/LICENSE` and therefore in the XLSX processor image at
`/srv/xlsx-processor-spike/node_modules/xlsx/LICENSE`.

License: <https://www.apache.org/licenses/LICENSE-2.0>

### Saxes 6.0.0

The XLSX processor transitively uses Saxes 6.0.0 under the ISC License.
The complete upstream notice, including the historical notices retained by
Saxes, is shipped in the processor image at
`/srv/xlsx-processor-spike/licenses/saxes-6.0.0-LICENSE`.

License source: <https://github.com/lddubeau/saxes/blob/v6.0.0/LICENSE>

### Tabulator 6.5.0

The XLSX browser viewer bundles Tabulator 6.5.0 under the MIT License:

> Copyright (c) 2015-2026 Oli Folkerd
>
> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to deal
> in the Software without restriction, including without limitation the rights
> to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
> copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
> OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
> SOFTWARE.

### LibreOffice 26.2.5

The DOCX processor image installs the official LibreOffice 26.2.5 binary
packages without modifying LibreOffice. LibreOffice is made available under
the Mozilla Public License 2.0 and includes components under additional
licenses. The image retains the complete upstream license and component
notices at `/opt/libreoffice26.2/LICENSE` and
`/opt/libreoffice26.2/LICENSE.html`.

License and source information: <https://www.libreoffice.org/licenses/>
Corresponding source for the pinned release:
<https://download.documentfoundation.org/libreoffice/src/26.2.5/>
