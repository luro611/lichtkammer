"""Tests für den Umwandler (HTML -> Blöcke). Aufruf: python3 -m unittest discover -s tools/wp-export -v
Alle Inhalte sind erfunden; es wird nichts aus dem Netz geladen."""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import wp_export as w  # noqa: E402


def media(*pairs):
    items = []
    for mid, url in pairs:
        items.append({"id": mid, "mime_type": "image/jpeg", "source_url": url, "alt_text": "", "title": {"rendered": ""}, "caption": {"rendered": ""},
                      "media_details": {"width": 100, "height": 100, "sizes": {}}})
    return w.Media(items, "https://alt.example")


def conv(html, m=None, **kw):
    m = m or media((1, "https://alt.example/wp-content/uploads/2022/01/a.jpg"), (2, "https://alt.example/wp-content/uploads/2022/01/b-scaled.jpg"))
    c = w.Converter(m, "alt.example", kw.get("gal", {}), kw.get("pages", {"/ueber-uns": "lk-page:ueber-uns"}), kw.get("files", {"/wp-content/uploads/f.pdf": "f.pdf"}))
    return c.blocks(w.parse_html(html).kids), c


class Umwandler(unittest.TestCase):
    def test_ueberschriften_und_absaetze(self):
        b, _ = conv('<h1>Eins</h1><h4>Vier</h4><p>Hallo <strong>Welt </strong>und <em>du</em>.</p><p>Zweiter</p>')
        self.assertEqual([x["t"] for x in b], ["heading", "heading", "text"])
        self.assertEqual((b[0]["level"], b[1]["level"]), (2, 3))
        self.assertEqual(b[2]["text"], "Hallo **Welt** und *du*.\n\nZweiter")  # Leerraum liegt außerhalb der Markierung

    def test_liste_zeilenumbruch_link(self):
        b, _ = conv('<p>A<br>B</p><ul><li>x <a href="https://www.alt.example/ueber-uns/">uns</a></li><li>y</li></ul>')
        self.assertEqual(b[0]["text"], "A\nB\n\n- x [uns](lk-page:ueber-uns)\n- y")

    def test_externe_und_unbekannte_links(self):
        b, _ = conv('<p><a href="https://fremd.de/x">F</a> <a href="javascript:alert(1)">J</a> <a href="mailto:a@b.de">M</a></p>')
        self.assertEqual(b[0]["text"], "[F](https://fremd.de/x) J [M](mailto:a@b.de)")

    def test_bild_mit_klasse_und_beschriftung(self):
        b, c = conv('<figure class="wp-block-image"><img class="wp-image-2" src="x" alt="Ein Bild"/><figcaption>Unter <em>schrift</em></figcaption></figure>')
        self.assertEqual(b, [{"t": "image", "wp": 2, "alt": "Ein Bild", "caption": "Unter schrift"}])
        self.assertEqual(c.used, [2])

    def test_bild_ueber_adresse_gefunden_auch_scaled_und_groessen(self):
        b, _ = conv('<p><img src="https://alt.example/wp-content/uploads/2022/01/b-300x200.jpg" alt=""></p>')
        self.assertEqual(b[0]["wp"], 2)

    def test_raster_wird_in_zwoelfer_bloecke_geteilt(self):
        items = [(i, f"https://alt.example/wp-content/uploads/g/{i}.jpg") for i in range(1, 31)]
        m = media(*items)
        h = '<div class="rl-gallery-container"><div class="rl-gallery">' + "".join(
            f'<div class="rl-gallery-item"><a class="rl-gallery-link" href="https://alt.example/wp-content/uploads/g/{i}.jpg"><img src="x"></a></div>' for i in range(1, 31)) + "</div></div>"
        b, _ = conv(h, m)
        self.assertEqual([len(x["photos"]) for x in b], [12, 12, 6])

    def test_pdf_block_wird_knopf(self):
        b, _ = conv('<div class="wp-block-file"><object data="x"></object><a href="https://alt.example/wp-content/uploads/f.pdf">Flyer</a><a class="wp-block-file__button" href="https://alt.example/wp-content/uploads/f.pdf">Download</a></div>')
        self.assertEqual(b, [{"t": "button", "label": "Flyer (PDF)", "href": "lk-file:f.pdf"}])

    def test_tabelle_wird_liste_und_trennlinie(self):
        b, _ = conv("<hr><table><tr><th>A</th><td>B</td></tr></table>")
        self.assertEqual([x["t"] for x in b], ["rule", "text"])
        self.assertEqual(b[1]["text"], "- A – B")

    def test_langer_text_wird_geteilt(self):
        b, _ = conv("".join(f"<p>{'x' * 900}</p>" for _ in range(60)))
        self.assertTrue(all(len(x["text"]) <= 19000 for x in b) and len(b) >= 3)

    def test_skripte_werden_ignoriert(self):
        b, _ = conv("<script>alert(1)</script><p>ok</p>")
        self.assertEqual(b, [{"t": "text", "text": "ok"}])


class Hilfen(unittest.TestCase):
    def test_titel_aus_dateiname_wird_verworfen(self):
        self.assertEqual(w.meaningful_title("MB Selbst Spiegel 2", "MB-Selbst-Spiegel-2.tif"), "")
        self.assertEqual(w.meaningful_title("_7RZ4377", "_7RZ4377-scaled.jpg"), "")
        self.assertEqual(w.meaningful_title("Abendlicht am Hafen", "DSC001.jpg"), "Abendlicht am Hafen")

    def test_beschriftung_aus_adresse_wird_verworfen(self):
        self.assertEqual(w.meaningful_caption("<p>https://x.de/a.jpg</p>"), "")
        self.assertEqual(w.meaningful_caption("<p>Start … Continue readingDSC1</p>"), "")
        self.assertEqual(w.meaningful_caption("<p>Abend</p>"), "Abend")

    def test_titel_in_ort_und_jahr(self):
        self.assertEqual(w.split_title("Zeche Zollverein 2016/11"), ("Zeche Zollverein", 2016))
        self.assertEqual(w.split_title("Heidelberg 2017/05 | Mannheim"), ("Heidelberg", 2017))
        self.assertEqual(w.split_title("Wuppertal 1979-80"), ("Wuppertal", 1979))
        self.assertEqual(w.split_title("Ohne Jahr"), ("", None))

    def test_dateiname_sicher(self):
        self.assertEqual(w.safe_name("../../etc/pa ss§wd.jpg"), "etc_pa_ss_wd.jpg"[-len("etc_pa_ss_wd.jpg"):])
        self.assertNotIn("/", w.safe_name("a/b"))

    def test_pdf_name_endet_immer_auf_klein_pdf(self):
        self.assertEqual(w.pdf_name("Flyer.PDF"), "Flyer.pdf")
        self.assertEqual(w.pdf_name("a" * 300 + ".pdf"), "a" * 110 + ".pdf")
        self.assertEqual(w.pdf_name("bericht"), "bericht.pdf")

    def test_bildformat_erkennung(self):
        self.assertEqual(w.sniff(b"\xff\xd8\xff\xe0abc"), "jpg")
        self.assertEqual(w.sniff(b"<?php"), "")


if __name__ == "__main__":
    unittest.main()
