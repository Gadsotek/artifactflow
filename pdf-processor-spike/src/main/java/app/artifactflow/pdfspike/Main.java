package app.artifactflow.pdfspike;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.StringWriter;
import java.io.Writer;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.net.URI;
import java.net.URISyntaxException;
import java.util.Collections;
import java.util.IdentityHashMap;
import java.util.Set;
import java.util.concurrent.CountDownLatch;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.cos.COSString;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.encryption.AccessPermission;
import org.apache.pdfbox.pdmodel.encryption.StandardProtectionPolicy;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.interactive.action.PDActionJavaScript;
import org.apache.pdfbox.pdmodel.interactive.action.PDActionURI;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.text.PDFTextStripper;

public final class Main {
    private static final int MAX_INPUT_BYTES = 16 * 1024 * 1024;
    private static final int MAX_PAGES = 250;
    private static final int MAX_TEXT_CHARACTERS = 2_000_000;
    private static final int MAX_GRAPH_DEPTH = 64;
    private static final int MAX_GRAPH_OBJECTS = 100_000;

    private Main() {
    }

    public static void main(String[] arguments) {
        try {
            if (arguments.length == 1 && "self-test".equals(arguments[0])) {
                selfTest();
                System.out.println("pdf-processor-spike self-test passed");
                return;
            }

            if (arguments.length == 1 && "hang-for-timeout-test".equals(arguments[0])) {
                new CountDownLatch(1).await();
                return;
            }

            if (arguments.length == 2 && "inspect".equals(arguments[0])) {
                byte[] bytes = readBounded(Path.of(arguments[1]));
                Inspection inspection = inspect(bytes, Limits.defaults(), false);
                System.out.println(inspection.toJson());
                return;
            }

            if (arguments.length == 2 && "inspect-docx-preview".equals(arguments[0])) {
                byte[] bytes = readBounded(Path.of(arguments[1]));
                Inspection inspection = inspect(bytes, Limits.defaults(), true);
                System.out.println(inspection.toJson());
                return;
            }

            System.err.println("usage: self-test | inspect /path/to/file.pdf | inspect-docx-preview /path/to/file.pdf");
            System.exit(64);
        } catch (RejectedPdf exception) {
            System.err.println("rejected: " + exception.reason);
            System.exit(65);
        } catch (IOException exception) {
            System.err.println("processing failed");
            System.exit(74);
        } catch (InterruptedException exception) {
            Thread.currentThread().interrupt();
            System.err.println("processing interrupted");
            System.exit(75);
        }
    }

    private static byte[] readBounded(Path path) throws IOException, RejectedPdf {
        try (InputStream input = Files.newInputStream(path)) {
            byte[] bytes = input.readNBytes(MAX_INPUT_BYTES + 1);
            if (bytes.length == 0 || bytes.length > MAX_INPUT_BYTES) {
                throw new RejectedPdf("input_size");
            }

            return bytes;
        }
    }

    private static Inspection inspect(byte[] bytes, Limits limits, boolean allowPassiveLinks) throws RejectedPdf {
        requirePdfEnvelope(bytes, limits.maxInputBytes);

        try (PDDocument document = Loader.loadPDF(bytes)) {
            if (document.isEncrypted()) {
                throw new RejectedPdf("encrypted");
            }

            int pages = document.getNumberOfPages();
            if (pages <= 0 || pages > limits.maxPages) {
                throw new RejectedPdf("page_limit");
            }

            rejectObviousActiveContent(document, allowPassiveLinks);

            StringBuilder extracted = new StringBuilder();
            boolean truncated = false;

            for (int page = 1; page <= pages; page++) {
                int remaining = limits.maxTextCharacters - extracted.length();
                if (remaining <= 0) {
                    truncated = true;
                    break;
                }

                PDFTextStripper stripper = new PDFTextStripper();
                stripper.setStartPage(page);
                stripper.setEndPage(page);
                LimitedWriter writer = new LimitedWriter(remaining);
                stripper.writeText(document, writer);
                extracted.append(writer.contents());

                if (writer.truncated()) {
                    truncated = true;
                    break;
                }
            }

            return new Inspection(pages, document.getVersion(), extracted.toString(), truncated);
        } catch (RejectedPdf exception) {
            throw exception;
        } catch (IOException | RuntimeException exception) {
            throw new RejectedPdf("invalid_pdf");
        }
    }

    private static void requirePdfEnvelope(byte[] bytes, int maxInputBytes) throws RejectedPdf {
        byte[] header = "%PDF-".getBytes(StandardCharsets.US_ASCII);
        if (bytes.length == 0 || bytes.length > maxInputBytes || bytes.length < header.length) {
            throw new RejectedPdf("input_size");
        }

        for (int index = 0; index < header.length; index++) {
            if (bytes[index] != header[index]) {
                throw new RejectedPdf("invalid_header");
            }
        }

        int cursor = bytes.length - 1;
        while (cursor >= 0 && isPdfWhitespace(bytes[cursor])) {
            cursor--;
        }

        byte[] eof = "%%EOF".getBytes(StandardCharsets.US_ASCII);
        if (cursor + 1 < eof.length) {
            throw new RejectedPdf("invalid_eof");
        }

        for (int index = 0; index < eof.length; index++) {
            if (bytes[cursor - eof.length + 1 + index] != eof[index]) {
                throw new RejectedPdf("invalid_eof");
            }
        }
    }

    private static boolean isPdfWhitespace(byte value) {
        return value == 0 || value == 9 || value == 10 || value == 12 || value == 13 || value == 32;
    }

    private static void rejectObviousActiveContent(PDDocument document, boolean allowPassiveLinks) throws RejectedPdf {
        Set<COSBase> visited = Collections.newSetFromMap(new IdentityHashMap<>());
        int[] objectCount = {0};
        inspectObject(document.getDocument().getTrailer(), visited, objectCount, 0, allowPassiveLinks);
    }

    private static void inspectObject(
        COSBase value,
        Set<COSBase> visited,
        int[] objectCount,
        int depth,
        boolean allowPassiveLinks
    ) throws RejectedPdf {
        if (value == null) {
            return;
        }
        if (depth > MAX_GRAPH_DEPTH || ++objectCount[0] > MAX_GRAPH_OBJECTS) {
            throw new RejectedPdf("object_limit");
        }

        COSBase resolved = value instanceof COSObject object ? object.getObject() : value;
        if (resolved == null || !visited.add(resolved)) {
            return;
        }

        if (resolved instanceof COSDictionary dictionary) {
            COSBase type = dictionary.getDictionaryObject(COSName.TYPE);
            if (type instanceof COSName typeName && "Action".equals(typeName.getName())) {
                if (!allowPassiveLinks) {
                    throw new RejectedPdf("active_content");
                }

                validatePassiveLinkAction(dictionary);
            } else if (type instanceof COSName typeName && isRejectedType(typeName.getName())) {
                throw new RejectedPdf("active_content");
            }

            COSBase subtype = dictionary.getDictionaryObject(COSName.SUBTYPE);
            if (subtype instanceof COSName subtypeName && "Link".equals(subtypeName.getName())) {
                if (!allowPassiveLinks) {
                    throw new RejectedPdf("active_content");
                }

                validatePassiveLinkAnnotation(dictionary);
            } else if (subtype instanceof COSName subtypeName && isRejectedAnnotationSubtype(subtypeName.getName())) {
                throw new RejectedPdf(
                    "Widget".equals(subtypeName.getName()) ? "interactive_form" : "active_content"
                );
            }

            for (COSName key : dictionary.keySet()) {
                String keyName = key.getName();
                if (
                    "JS".equals(keyName)
                        || "JavaScript".equals(keyName)
                        || "EmbeddedFiles".equals(keyName)
                        || "EF".equals(keyName)
                        || "AcroForm".equals(keyName)
                        || "XFA".equals(keyName)
                        || "OpenAction".equals(keyName)
                        || "AA".equals(keyName)
                        || "RichMediaContent".equals(keyName)
                        || "RichMediaSettings".equals(keyName)
                        || "3DD".equals(keyName)
                        || "3DA".equals(keyName)
                        || "3DV".equals(keyName)
                ) {
                    throw new RejectedPdf(
                        "AcroForm".equals(keyName) || "XFA".equals(keyName)
                            ? "interactive_form"
                            : "active_content"
                    );
                }

                if (COSName.S.equals(key) && !(allowPassiveLinks && isActionDictionary(type))) {
                    COSBase action = dictionary.getDictionaryObject(key);
                    if (action instanceof COSName actionName && isRejectedAction(actionName.getName())) {
                        throw new RejectedPdf("active_content");
                    }
                }

                inspectObject(dictionary.getDictionaryObject(key), visited, objectCount, depth + 1, allowPassiveLinks);
            }
            return;
        }

        if (resolved instanceof COSArray array) {
            for (int index = 0; index < array.size(); index++) {
                inspectObject(array.getObject(index), visited, objectCount, depth + 1, allowPassiveLinks);
            }
        }
    }

    private static boolean isActionDictionary(COSBase type) {
        return type instanceof COSName typeName && "Action".equals(typeName.getName());
    }

    private static void validatePassiveLinkAnnotation(COSDictionary annotation) throws RejectedPdf {
        COSBase action = annotation.getDictionaryObject(COSName.A);
        COSBase destination = annotation.getDictionaryObject(COSName.DEST);

        if ((action == null) == (destination == null)) {
            throw new RejectedPdf("active_content");
        }

        if (action != null) {
            COSBase resolved = action instanceof COSObject object ? object.getObject() : action;

            if (!(resolved instanceof COSDictionary actionDictionary)) {
                throw new RejectedPdf("active_content");
            }

            validatePassiveLinkAction(actionDictionary);
        } else if (!isBoundedInternalDestination(destination)) {
            throw new RejectedPdf("active_content");
        }
    }

    private static void validatePassiveLinkAction(COSDictionary action) throws RejectedPdf {
        COSBase actionName = action.getDictionaryObject(COSName.S);

        if (!(actionName instanceof COSName name)) {
            throw new RejectedPdf("active_content");
        }

        if ("URI".equals(name.getName())) {
            COSBase rawUri = action.getDictionaryObject(COSName.URI);

            if (!(rawUri instanceof COSString uriString) || !isAllowedExternalUri(uriString.getString())) {
                throw new RejectedPdf("active_content");
            }

            return;
        }

        if ("GoTo".equals(name.getName()) && isBoundedInternalDestination(action.getDictionaryObject(COSName.D))) {
            return;
        }

        throw new RejectedPdf("active_content");
    }

    private static boolean isBoundedInternalDestination(COSBase destination) {
        COSBase resolved = destination instanceof COSObject object ? object.getObject() : destination;

        if (resolved instanceof COSName name) {
            return name.getName().length() <= 512;
        }

        if (resolved instanceof COSString string) {
            return string.getString().length() <= 512;
        }

        return resolved instanceof COSArray array && array.size() >= 1 && array.size() <= 8;
    }

    private static boolean isAllowedExternalUri(String value) {
        if (value.isEmpty() || value.length() > 2_048 || value.chars().anyMatch(character -> character < 0x20 || character == 0x7f)) {
            return false;
        }

        try {
            URI uri = new URI(value);
            String scheme = uri.getScheme();

            if (scheme == null || uri.getUserInfo() != null) {
                return false;
            }

            if ("mailto".equalsIgnoreCase(scheme)) {
                String address = uri.getRawSchemeSpecificPart();

                return address != null && !address.isEmpty() && !address.startsWith("//");
            }

            return ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme))
                && uri.getHost() != null
                && !uri.getHost().isEmpty()
                && uri.getRawAuthority() != null;
        } catch (URISyntaxException exception) {
            return false;
        }
    }

    private static boolean isRejectedAction(String action) {
        return switch (action) {
            case "GoTo", "GoToR", "GoToE", "Launch", "Thread", "URI", "Sound", "Movie", "Hide",
                "Named", "SubmitForm", "ResetForm", "ImportData", "JavaScript", "SetOCGState", "Rendition",
                "Trans", "GoTo3DView" -> true;
            default -> false;
        };
    }

    private static boolean isRejectedType(String type) {
        return "Action".equals(type) || "EmbeddedFile".equals(type);
    }

    private static boolean isRejectedAnnotationSubtype(String subtype) {
        return switch (subtype) {
            case "Link", "FileAttachment", "Widget", "RichMedia", "3D", "Movie", "Sound", "Screen" -> true;
            default -> false;
        };
    }

    private static void selfTest() throws IOException, RejectedPdf {
        byte[] benign = createTextPdf("hello <script>alert(1)</script>", 1);
        Inspection inspected = inspect(benign, Limits.defaults(), false);
        require(inspected.pages == 1, "benign page count");
        require(inspected.text.contains("<script>alert(1)</script>"), "script-shaped text remains text");

        expectRejected("renamed HTML", "<!doctype html><script>alert(1)</script>".getBytes(StandardCharsets.UTF_8));

        byte[] prefixed = new byte[benign.length + 6];
        System.arraycopy("<html>".getBytes(StandardCharsets.US_ASCII), 0, prefixed, 0, 6);
        System.arraycopy(benign, 0, prefixed, 6, benign.length);
        expectRejected("late PDF header", prefixed);

        byte[] trailing = new byte[benign.length + 8];
        System.arraycopy(benign, 0, trailing, 0, benign.length);
        System.arraycopy("<script>".getBytes(StandardCharsets.US_ASCII), 0, trailing, benign.length, 8);
        expectRejected("trailing script", trailing);

        expectRejected("encrypted PDF", createEncryptedPdf());
        expectRejected("page cap", createTextPdf("page", 2), new Limits(MAX_INPUT_BYTES, 1, MAX_TEXT_CHARACTERS));
        expectRejected("JavaScript action", createJavaScriptPdf());
        expectRejectedReason("AcroForm", createAcroFormPdf(), "interactive_form");
        expectRejected("embedded files", createEmbeddedFilesPdf());
        expectRejected("external URI action", createExternalUriActionPdf());
        expectRejected("named print action", createActionPdf("Named"));
        expectRejected("embedded go-to action", createActionPdf("GoToE"));
        expectRejected("link annotation", createAnnotationPdf("Link"));
        expectRejected("file attachment annotation", createAnnotationPdf("FileAttachment"));
        expectRejected("rich media annotation", createAnnotationPdf("RichMedia"));
        expectRejected("deep object graph", createDeepObjectGraphPdf());

        Inspection passiveLink = inspect(createPassiveUriLinkPdf("https://example.com/documentation"), Limits.defaults(), true);
        require(passiveLink.pages == 1, "DOCX-preview URI link is accepted only in the derived profile");
        expectRejectedDerived("credential-bearing URI link", createPassiveUriLinkPdf("https://user@example.com/path"));
        expectRejectedDerived("ambiguous HTTP URI link", createPassiveUriLinkPdf("http:relative"));
        expectRejectedDerived("authority-form mailto link", createPassiveUriLinkPdf("mailto://example.com/person"));
        expectRejectedDerived("unsafe URI link", createPassiveUriLinkPdf("javascript:alert(1)"));
        expectRejectedDerived("launch link", createPassiveActionLinkPdf("Launch"));

        byte[] truncated = new byte[benign.length / 2];
        System.arraycopy(benign, 0, truncated, 0, truncated.length);
        expectRejected("truncated PDF", truncated);

        Inspection imageOnly = inspect(createBlankPdf(), Limits.defaults(), false);
        require(imageOnly.text.isBlank(), "image-only PDF has no extracted text");

        Inspection unicode = inspect(createTextPdf("café", 2), Limits.defaults(), false);
        require(unicode.pages == 2, "multi-page count");
        require(unicode.text.contains("café"), "Unicode text extraction");

        Inspection outputCap = inspect(
            createTextPdf("0123456789abcdefghijklmnopqrstuvwxyz", 1),
            new Limits(MAX_INPUT_BYTES, MAX_PAGES, 12),
            false
        );
        require(outputCap.truncated, "output cap marks extraction as truncated");
        require(outputCap.text.length() == 12, "output cap bounds extracted text");
    }

    private static byte[] createTextPdf(String text, int pageCount) throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            PDType1Font font = new PDType1Font(Standard14Fonts.FontName.HELVETICA);
            for (int index = 0; index < pageCount; index++) {
                PDPage page = new PDPage();
                document.addPage(page);
                try (PDPageContentStream content = new PDPageContentStream(document, page)) {
                    content.beginText();
                    content.setFont(font, 12);
                    content.newLineAtOffset(40, 700);
                    content.showText(text);
                    content.endText();
                }
            }
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createEncryptedPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            StandardProtectionPolicy policy = new StandardProtectionPolicy(
                "owner-password",
                "user-password",
                new AccessPermission()
            );
            policy.setEncryptionKeyLength(128);
            document.protect(policy);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createJavaScriptPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            document.getDocumentCatalog().setOpenAction(new PDActionJavaScript("app.alert('no')"));
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createAcroFormPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            document.getDocumentCatalog().setAcroForm(new PDAcroForm(document));
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createEmbeddedFilesPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            COSDictionary names = new COSDictionary();
            names.setItem(COSName.EMBEDDED_FILES, new COSDictionary());
            document.getDocumentCatalog().getCOSObject().setItem(COSName.NAMES, names);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createExternalUriActionPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            PDActionURI action = new PDActionURI();
            action.setURI("https://example.com/should-not-open");
            document.getDocumentCatalog().setOpenAction(action);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createActionPdf(String actionName) throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            COSDictionary action = new COSDictionary();
            action.setItem(COSName.TYPE, COSName.getPDFName("Action"));
            action.setItem(COSName.S, COSName.getPDFName(actionName));
            document.getDocumentCatalog().getCOSObject().setItem(COSName.OPEN_ACTION, action);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createAnnotationPdf(String subtypeName) throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            PDPage page = new PDPage();
            document.addPage(page);
            COSDictionary annotation = new COSDictionary();
            annotation.setItem(COSName.TYPE, COSName.ANNOT);
            annotation.setItem(COSName.SUBTYPE, COSName.getPDFName(subtypeName));
            COSArray annotations = new COSArray();
            annotations.add(annotation);
            page.getCOSObject().setItem(COSName.ANNOTS, annotations);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createDeepObjectGraphPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            COSDictionary cursor = document.getDocumentCatalog().getCOSObject();
            for (int depth = 0; depth <= MAX_GRAPH_DEPTH; depth++) {
                COSDictionary child = new COSDictionary();
                cursor.setItem(COSName.getPDFName("ArtifactFlowDepth" + depth), child);
                cursor = child;
            }
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createPassiveUriLinkPdf(String target) throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            PDPage page = new PDPage();
            document.addPage(page);
            COSDictionary action = new COSDictionary();
            action.setItem(COSName.TYPE, COSName.getPDFName("Action"));
            action.setItem(COSName.S, COSName.getPDFName("URI"));
            action.setString(COSName.URI, target);
            COSDictionary annotation = new COSDictionary();
            annotation.setItem(COSName.TYPE, COSName.ANNOT);
            annotation.setItem(COSName.SUBTYPE, COSName.getPDFName("Link"));
            annotation.setItem(COSName.A, action);
            COSArray annotations = new COSArray();
            annotations.add(annotation);
            page.getCOSObject().setItem(COSName.ANNOTS, annotations);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createPassiveActionLinkPdf(String actionName) throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            PDPage page = new PDPage();
            document.addPage(page);
            COSDictionary action = new COSDictionary();
            action.setItem(COSName.TYPE, COSName.getPDFName("Action"));
            action.setItem(COSName.S, COSName.getPDFName(actionName));
            COSDictionary annotation = new COSDictionary();
            annotation.setItem(COSName.TYPE, COSName.ANNOT);
            annotation.setItem(COSName.SUBTYPE, COSName.getPDFName("Link"));
            annotation.setItem(COSName.A, action);
            COSArray annotations = new COSArray();
            annotations.add(annotation);
            page.getCOSObject().setItem(COSName.ANNOTS, annotations);
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] createBlankPdf() throws IOException {
        try (PDDocument document = new PDDocument(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            document.addPage(new PDPage());
            document.save(output);
            return output.toByteArray();
        }
    }

    private static void expectRejected(String label, byte[] bytes) {
        expectRejected(label, bytes, Limits.defaults());
    }

    private static void expectRejected(String label, byte[] bytes, Limits limits) {
        try {
            inspect(bytes, limits, false);
            throw new IllegalStateException("expected rejection: " + label);
        } catch (RejectedPdf expected) {
            // Expected by the synthetic hostile corpus.
        }
    }

    private static void expectRejectedReason(String label, byte[] bytes, String reason) {
        try {
            inspect(bytes, Limits.defaults(), false);
            throw new IllegalStateException("expected rejection: " + label);
        } catch (RejectedPdf expected) {
            require(reason.equals(expected.reason), label + " rejection reason");
        }
    }

    private static void expectRejectedDerived(String label, byte[] bytes) {
        try {
            inspect(bytes, Limits.defaults(), true);
            throw new IllegalStateException("expected derived-profile rejection: " + label);
        } catch (RejectedPdf expected) {
            // Expected by the derived-preview hostile corpus.
        }
    }

    private static void require(boolean condition, String label) {
        if (!condition) {
            throw new IllegalStateException("self-test failed: " + label);
        }
    }

    private record Limits(int maxInputBytes, int maxPages, int maxTextCharacters) {
        private static Limits defaults() {
            return new Limits(MAX_INPUT_BYTES, MAX_PAGES, MAX_TEXT_CHARACTERS);
        }
    }

    private record Inspection(int pages, float pdfVersion, String text, boolean truncated) {
        private String toJson() {
            return "{\"pages\":" + pages
                + ",\"pdf_version\":\"" + pdfVersion + "\""
                + ",\"truncated\":" + truncated
                + ",\"text\":\"" + jsonEscape(text) + "\"}";
        }
    }

    private static final class LimitedWriter extends Writer {
        private final int limit;
        private final StringWriter writer = new StringWriter();
        private boolean truncated;

        private LimitedWriter(int limit) {
            this.limit = limit;
        }

        @Override
        public void write(char[] characters, int offset, int length) {
            int remaining = limit - writer.getBuffer().length();
            if (remaining <= 0) {
                truncated = true;
                return;
            }
            int accepted = Math.min(length, remaining);
            writer.write(characters, offset, accepted);
            truncated = truncated || accepted < length;
        }

        @Override
        public void flush() {
        }

        @Override
        public void close() {
        }

        private String contents() {
            return writer.toString();
        }

        private boolean truncated() {
            return truncated;
        }
    }

    private static final class RejectedPdf extends Exception {
        private final String reason;

        private RejectedPdf(String reason) {
            super(reason);
            this.reason = reason;
        }
    }

    private static String jsonEscape(String value) {
        StringBuilder escaped = new StringBuilder(value.length());
        for (int index = 0; index < value.length(); index++) {
            char character = value.charAt(index);
            switch (character) {
                case '"' -> escaped.append("\\\"");
                case '\\' -> escaped.append("\\\\");
                case '\b' -> escaped.append("\\b");
                case '\f' -> escaped.append("\\f");
                case '\n' -> escaped.append("\\n");
                case '\r' -> escaped.append("\\r");
                case '\t' -> escaped.append("\\t");
                default -> {
                    if (character < 0x20) {
                        escaped.append(String.format("\\u%04x", (int) character));
                    } else {
                        escaped.append(character);
                    }
                }
            }
        }
        return escaped.toString();
    }
}
