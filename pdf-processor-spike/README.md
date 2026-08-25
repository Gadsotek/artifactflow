# PDF processor spike

This directory contains the bounded PDFBox CLI and the authenticated
single-worker HTTP service used by both reviewed processor topologies.

Run the isolated self-test with:

```sh
make pdf-processor-spike-test
make pdf-processor-service-test
make pdf-processor-private-service-test
```

The first service target runs without a network, writable root filesystem,
Linux capabilities, or elevated privileges. The private-network target runs on
an ordinary container network but starts PHP and every PDFBox child through an
inherited seccomp launcher; its runtime test requires the outbound-denial
self-test log and a healthy HTTP boundary. Passing proves only the image-level
contract. A production deployment must still prove private-only reachability,
resource limits, digest/attestation verification, and the operator gates in
`docs/architecture/pdf-artifacts.md`.
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
