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
self-test log, SCTP socket denial, and a health boundary that fails when the
listener or PDFBox engine is unavailable. A fixed health process outside the
parser filter may connect only to the container's loopback `/health` endpoint
and handles no document bytes. Every probe carries a fresh timestamp, nonce,
and domain-separated HMAC under the processor secret. That route admits only
the direct loopback peer, ignores forwarded client-address claims, and verifies
the signature before engine admission, so a loopback TLS sidecar does not turn
external health traffic into native work. The server and every PDFBox child stay
inside the inherited filter. Both service targets wrap each native engine in a second
seccomp boundary that permits JVM threads but denies child-process creation.
Their runtime probes prove a timed-out engine cannot retain a descendant or
observe a later request's temporary input. Passing proves only the image-level
contract. A production deployment must still prove private-only reachability,
authenticated HTTPS from the app when no Unix socket is available, resource
limits, digest/attestation verification, and the operator gates in
`docs/architecture/pdf-artifacts.md`. The processor image does not terminate
TLS itself; use a trusted private proxy or sidecar without assigning the
processor a public domain.
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
