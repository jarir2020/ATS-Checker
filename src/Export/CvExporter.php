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
        $text = CvRenderer::text($cv);
        $paragraphs = [];
        foreach (explode("\n", trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') { $paragraphs[] = '<w:p/>'; continue; }
            $style = preg_match('/^[A-Z][A-Z &\/()-]{2,}$/', $line) ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : '';
            $bullet = strpos($line, '- ') === 0;
            if ($bullet) { $line = substr($line, 2); $style = '<w:pPr><w:ind w:left="360"/></w:pPr>'; }
            $paragraphs[] = '<w:p>' . $style . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . implode('', $paragraphs) . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850"/></w:sectPr></w:body></w:document>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style></w:styles>');
        $zip->close();
        $body = (string)file_get_contents($temp);
        @unlink($temp);
        return $body;
    }
}
