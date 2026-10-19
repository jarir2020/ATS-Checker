<?php
namespace ATS\Export;

use ATS\Cv\CvDocument;
use ATS\Cv\CvRenderer;

final class CvExporter
{
    public function export(array $cv, string $format): array
    {
        $cv = CvDocument::normalize($cv);
        if ($format === 'html') return ['mime' => 'text/html; charset=utf-8', 'extension' => 'html', 'body' => CvRenderer::html($cv)];
        if ($format === 'txt') return ['mime' => 'text/plain; charset=utf-8', 'extension' => 'txt', 'body' => CvRenderer::text($cv)];
        if ($format === 'pdf') return ['mime' => 'application/pdf', 'extension' => 'pdf', 'body' => $this->pdf($cv)];
        if ($format === 'docx') return ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extension' => 'docx', 'body' => $this->docx($cv)];
        throw new \InvalidArgumentException('Choose DOCX, PDF, TXT, or HTML.');
    }

    private function pdf(array $cv): string
    {
        if (!class_exists(\Mpdf\Mpdf::class)) throw new \RuntimeException('PDF export is unavailable because mPDF is not installed.');
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
        $pdf->SetTitle($cv['personal']['name'] ?: 'CV');
        $pdf->WriteHTML(CvRenderer::html($cv));
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function docx(array $cv): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('DOCX export requires the PHP zip extension.');
        $temp = tempnam(sys_get_temp_dir(), 'ats-docx-');
        if ($temp === false) throw new \RuntimeException('Could not prepare a temporary DOCX file.');
        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { @unlink($temp); throw new \RuntimeException('Could not create a DOCX document.'); }
        $photoBytes = '';
        $lines = explode("\n", trim(CvRenderer::text($cv)));
        $headerHtml = '';
        if ($cv['personal']['photo'] !== '') {
            $personal = $cv['personal'];
            $contact = implode(' | ', array_filter([$personal['email'], $personal['phone'], $personal['location'], $personal['linkedin'], $personal['github']], static fn($value) => trim($value) !== ''));
            $headerLines = array_values(array_filter([$personal['name'], $personal['title'], $contact], static fn($value) => trim($value) !== ''));
            $headerCount = count($headerLines);
            $lines = array_slice($lines, $headerCount);
            $photoBytes = base64_decode(substr($personal['photo'], strlen('data:image/jpeg;base64,')), true) ?: '';
            $headerHtml = $this->docxHeaderTable($headerLines, $photoBytes);
        }
        $paragraphs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { $paragraphs[] = '<w:p/>'; continue; }
            $style = preg_match('/^[A-Z][A-Z &\/()-]{2,}$/', $line) ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : '';
            $bullet = strpos($line, '- ') === 0;
            if ($bullet) { $line = substr($line, 2); $style = '<w:pPr><w:ind w:left="360"/></w:pPr>'; }
            $paragraphs[] = '<w:p>' . $style . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
        }
        $imageNamespaces = $photoBytes !== '' ? ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"' : '';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"' . $imageNamespaces . '><w:body>' . $headerHtml . implode('', $paragraphs) . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850"/></w:sectPr></w:body></w:document>';
        $imageContentType = $photoBytes !== '' ? '<Default Extension="jpg" ContentType="image/jpeg"/>' : '';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $imageContentType . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $imageRelationship = $photoBytes !== '' ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/profile.jpg"/>' : '';
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . $imageRelationship . '</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        if ($photoBytes !== '') $zip->addFromString('word/media/profile.jpg', $photoBytes);
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style></w:styles>');
        $zip->close();
        $body = (string)file_get_contents($temp);
        @unlink($temp);
        return $body;
    }

    private function docxHeaderTable(array $lines, string $photoBytes): string
    {
        $cells = [];
        foreach ($lines as $index => $line) {
            $size = $index === 0 ? 36 : ($index === 1 ? 22 : 18);
            $bold = $index === 0 ? '<w:b/>' : '';
            $text = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr><w:r><w:rPr>' . $bold . '<w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
        }
        if (!$cells) $cells[] = '<w:p/>';
        $image = '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="914400" cy="1219200"/><wp:docPr id="1" name="Profile photo" descr="Profile photo"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="profile.jpg"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="1219200"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        return '<w:tbl><w:tblPr><w:tblW w:w="10206" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="8306"/><w:gridCol w:w="1900"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="8306" w:type="dxa"/></w:tcPr>' . implode('', $cells) . '</w:tc><w:tc><w:tcPr><w:tcW w:w="1900" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>' . $image . '</w:tc></w:tr></w:tbl>';
    }
}
