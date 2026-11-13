package app.artifactflow.pdfspike;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Collections;
import java.util.HashMap;
import java.util.IdentityHashMap;
import java.util.Map;
import java.util.Set;

import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSNull;
import org.apache.pdfbox.cos.COSNumber;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.cos.COSString;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;

/** Only explicitly selected page links and outline items may own navigation actions. */
final class PdfNavigation {
    private final Set<COSBase> pages = identitySet();
    private final Set<COSBase> links = identitySet();
    private final Set<COSBase> actions = identitySet();
    private final Map<String, COSBase> destinations = new HashMap<>();
    private final Map<String, COSBase> legacyDestinations = new HashMap<>();
    private final boolean allowExternalLinks;
    private int work;

    PdfNavigation(PDDocument document, boolean docxPreview) throws Main.RejectedPdf {
        allowExternalLinks = !docxPreview;
        for (PDPage page : document.getPages()) {
            if (pages.size() >= 250 || !pages.add(page.getCOSObject())) reject();
        }
        COSDictionary catalog = document.getDocumentCatalog().getCOSObject();
        COSBase legacy = catalog.getDictionaryObject(COSName.DESTS);
        if (legacy != null) {
            if (!(legacy instanceof COSDictionary dictionary)) reject();
            for (COSName key : ((COSDictionary) legacy).keySet()) {
                addDestination(legacyDestinations, key.getName(), ((COSDictionary) legacy).getDictionaryObject(key));
            }
        }
        COSBase names = catalog.getDictionaryObject(COSName.NAMES);
        if (names instanceof COSDictionary dictionary && dictionary.containsKey(COSName.DESTS)) {
            collectNames(dictionary.getDictionaryObject(COSName.DESTS), identitySet(), 0);
        }
        for (PDPage page : document.getPages()) {
            COSBase annotations = page.getCOSObject().getDictionaryObject(COSName.ANNOTS);
            if (annotations == null) continue;
            if (!(annotations instanceof COSArray)) reject();
            COSArray array = (COSArray) annotations;
            for (int index = 0; index < array.size(); index++) {
                account(0);
                COSBase value = array.getObject(index);
                if (value instanceof COSDictionary annotation
                    && COSName.getPDFName("Link").equals(annotation.getDictionaryObject(COSName.SUBTYPE))) {
                    if (annotation.containsKey(COSName.getPDFName("PA"))) reject();
                    validateOwner(annotation);
                    links.add(annotation);
                }
            }
        }
        COSBase outlines = catalog.getDictionaryObject(COSName.OUTLINES);
        if (outlines instanceof COSDictionary root) {
            collectOutlines(root.getDictionaryObject(COSName.FIRST), identitySet(), 0);
        }
    }

    boolean permitsAction(COSDictionary action) {
        return actions.contains(action);
    }

    boolean permitsLink(COSDictionary link) {
        return links.contains(link);
    }

    private void collectOutlines(COSBase value, Set<COSBase> seen, int depth) throws Main.RejectedPdf {
        while (value != null) {
            account(depth);
            if (!(value instanceof COSDictionary) || !seen.add(value)) reject();
            COSDictionary item = (COSDictionary) value;
            validateOwner(item);
            collectOutlines(item.getDictionaryObject(COSName.FIRST), seen, depth + 1);
            value = item.getDictionaryObject(COSName.NEXT);
        }
    }

    private void collectNames(COSBase value, Set<COSBase> seen, int depth) throws Main.RejectedPdf {
        account(depth);
        if (!(value instanceof COSDictionary) || !seen.add(value)) reject();
        COSDictionary node = (COSDictionary) value;
        COSBase names = node.getDictionaryObject(COSName.NAMES);
        COSBase kids = node.getDictionaryObject(COSName.KIDS);
        if ((names == null) == (kids == null)) reject();
        if (names != null) {
            if (!(names instanceof COSArray) || ((COSArray) names).size() % 2 != 0) reject();
            COSArray pairs = (COSArray) names;
            for (int index = 0; index < pairs.size(); index += 2) {
                if (!(pairs.getObject(index) instanceof COSString)) reject();
                addDestination(destinations, ((COSString) pairs.getObject(index)).getString(), pairs.getObject(index + 1));
            }
        } else {
            if (!(kids instanceof COSArray)) reject();
            COSArray children = (COSArray) kids;
            for (int index = 0; index < children.size(); index++) {
                collectNames(children.getObject(index), seen, depth + 1);
            }
        }
    }

    private void addDestination(Map<String, COSBase> table, String name, COSBase value) throws Main.RejectedPdf {
        account(0);
        if (name.isEmpty() || name.length() > 512 || value == null || table.putIfAbsent(name, value) != null) reject();
    }

    private void validateOwner(COSDictionary owner) throws Main.RejectedPdf {
        COSBase action = owner.getDictionaryObject(COSName.A);
        COSBase destination = owner.getDictionaryObject(COSName.DEST);
        if (action != null && destination != null) reject();
        if (action != null) {
            if (!(action instanceof COSDictionary)) reject();
            validateAction((COSDictionary) action);
            actions.add(action);
        } else if (destination != null) {
            validateDestination(destination);
        }
    }

    private void validateAction(COSDictionary action) throws Main.RejectedPdf {
        COSBase type = action.getDictionaryObject(COSName.TYPE);
        if (type != null && !COSName.getPDFName("Action").equals(type)) reject();
        COSBase name = action.getDictionaryObject(COSName.S);
        Set<COSName> keys;
        if (COSName.getPDFName("GoTo").equals(name)) {
            keys = Set.of(COSName.TYPE, COSName.S, COSName.D);
            validateDestination(action.getDictionaryObject(COSName.D));
        } else if (allowExternalLinks && COSName.getPDFName("URI").equals(name)) {
            COSName isMap = COSName.getPDFName("IsMap");
            keys = Set.of(COSName.TYPE, COSName.S, COSName.URI, isMap);
            if (action.containsKey(isMap) && !org.apache.pdfbox.cos.COSBoolean.FALSE.equals(action.getDictionaryObject(isMap))) reject();
            COSBase target = action.getDictionaryObject(COSName.URI);
            if (!(target instanceof COSString) || !safeUri(((COSString) target).getString())) reject();
        } else {
            reject();
            return;
        }
        if (!keys.containsAll(action.keySet())) reject(); // Includes action chains (Next).
    }

    private void validateDestination(COSBase value) throws Main.RejectedPdf {
        value = value instanceof COSObject object ? object.getObject() : value;
        if (value instanceof COSName || value instanceof COSString) {
            String name = value instanceof COSName key ? key.getName() : ((COSString) value).getString();
            if (name.isEmpty() || name.length() > 512) reject();
            // PDF 1.1 names and PDF 1.2 strings use distinct destination registries.
            Map<String, COSBase> primary = value instanceof COSName ? legacyDestinations : destinations;
            Map<String, COSBase> fallback = value instanceof COSName ? destinations : legacyDestinations;
            value = primary.containsKey(name) ? primary.get(name) : fallback.get(name);
        }
        if (value instanceof COSDictionary dictionary) value = dictionary.getDictionaryObject(COSName.D);
        if (!(value instanceof COSArray)) reject();
        COSArray destination = (COSArray) value;
        if (destination.size() < 2 || !pages.contains(destination.getObject(0))
            || !(destination.getObject(1) instanceof COSName)) reject();
        String mode = ((COSName) destination.getObject(1)).getName();
        int size = switch (mode) {
            case "XYZ" -> 5;
            case "Fit", "FitB" -> 2;
            case "FitH", "FitV", "FitBH", "FitBV" -> 3;
            case "FitR" -> 6;
            default -> -1;
        };
        if (destination.size() != size) reject();
        for (int index = 2; index < destination.size(); index++) {
            COSBase parameter = destination.getObject(index);
            if ((parameter == null || parameter instanceof COSNull) && !"FitR".equals(mode)) continue;
            if (!(parameter instanceof COSNumber number) || !Float.isFinite(number.floatValue())) reject();
        }
    }

    private static boolean safeUri(String value) {
        if (value.isEmpty() || value.length() > 2048 || value.chars().anyMatch(c -> c <= 32 || c == 127 || c == '\\')
            || value.matches("(?is).*%(?:0[0-9a-f]|1[0-9a-f]|7f|5c).*")) return false;
        try {
            URI uri = new URI(value);
            String scheme = uri.getScheme();
            if ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
                return uri.getHost() != null && uri.getRawUserInfo() == null && uri.getPort() <= 65535;
            }
            if ("mailto".equalsIgnoreCase(scheme)) {
                String address = uri.getRawSchemeSpecificPart().split("\\?", 2)[0];
                return uri.isOpaque() && !address.startsWith("/") && address.contains("@");
            }
        } catch (URISyntaxException ignored) {
            return false;
        }
        return false;
    }

    private void account(int depth) throws Main.RejectedPdf {
        if (depth > 64 || ++work > 100_000) throw new Main.RejectedPdf("object_limit");
    }

    private static Set<COSBase> identitySet() {
        return Collections.newSetFromMap(new IdentityHashMap<>());
    }

    private static void reject() throws Main.RejectedPdf {
        throw new Main.RejectedPdf("active_content");
    }
}
