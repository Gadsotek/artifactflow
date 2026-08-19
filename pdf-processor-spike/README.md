# PDF processor spike

**Non-production implementation candidate.** This directory contains the
bounded PDFBox CLI plus the authenticated single-worker HTTP shell being proven
before enablement. The HTTP shell is wired only into the isolated E2E Compose
profile; no production service topology exists. It must not be enabled in
production yet.

Run the isolated self-test with:

```sh
make pdf-processor-spike-test
make pdf-processor-service-test
```

The target builds the pinned candidate and runs it without a network, writable
root filesystem, Linux capabilities, or elevated privileges. Passing proves
only the checks in the current candidate; it is not approval to ship PDF support.
The target also starts an intentionally hung spike command and proves that the
external harness kills the entire container at its wall-clock deadline. The
HTTP shell holds a cross-process lease for every JVM invocation, so its health
probe cannot create a second native engine beside an admitted document.

PDFBox extracts text already embedded in the PDF. Ordinary digital PDFs need no
OCR. Image-only/scanned PDFs are accepted with `no_embedded_text`; OCR remains
out of scope.

The `inspect` command is intentionally local and technical:

```sh
docker run --rm --network none --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=32m \
  -v /absolute/path/to/file.pdf:/input.pdf:ro \
  artifactflow-pdf-processor-spike:local inspect /input.pdf
```

Do not use private documents as corpus fixtures. Only synthetic or explicitly
redistributable hostile samples belong in the repository.
