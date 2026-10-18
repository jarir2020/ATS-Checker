<?php
namespace ATS\Ats;

final class AtsChecker
{
    private const STOPWORDS = ['about','after','against','also','and','are','around','been','before','being','between','both','can','could','during','each','from','have','into','more','most','other','our','over','such','than','that','their','them','then','there','these','they','this','those','through','under','using','very','was','were','which','while','with','work','your','you','the','for','but','not','all','any','has','had','its','who','what','when','where','will','would','should','must','may','able','role','team','company','job','responsibilities','requirements','experience','preferred'];

    public function inspect(string $text, string $jobDescription = ''): array
    {
        $possibleLayoutArtifacts = preg_match('/[│┃║]|\t{2,}/u', $text) === 1;
        $splitDateEvidence = '';
        if (preg_match('/(?<!\d)((?:0?[1-9]|1[0-2])\/(?:(?:0?[1-9]|[12]\d|3[01])\/)?)\h*(?=[\r\f]?$)/m', $text, $dateMatch)) {
            $splitDateEvidence = trim($dateMatch[1]);
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $findings = [];
        $score = 100;
        $add = static function (string $severity, string $title, string $message, int $penalty = 0, string $section = 'CV text', string $evidence = '') use (&$findings, &$score): void {
            $findings[] = ['severity' => $severity, 'title' => $title, 'message' => $message, 'section' => $section, 'evidence' => $evidence !== '' ? $evidence : $message];
            $score = max(0, $score - $penalty);
        };
        if (mb_strlen($text) < 250) $add('warning', 'Very little CV text was found', 'Add more detail or confirm that the uploaded document contains selectable text.', 12, 'Document text', mb_substr($text, 0, 180) ?: 'No extracted text was found.');
        if (!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text)) $add('warning', 'Email address not detected', 'Include a plain-text email address in the contact section.', 8, 'Contact details', 'No email address was detected in the extracted text.');
        $phoneSearchText = preg_replace('/\b(?:19|20)\d{2}\s*[-–]\s*(?:19|20)\d{2}\b/', '', $text) ?? $text;
        if (!preg_match('/(?:\+?\d[\d().\s-]{6,}\d)/', $phoneSearchText)) $add('suggestion', 'Phone number not detected', 'Add a phone number if you want employers to contact you by phone.', 3, 'Contact details', 'No phone number was detected in the extracted text.');

        $sections = [
            'Summary' => '/\b(summary|professional summary|profile|objective)\b/i',
            'Experience' => '/\b(experience|work history|employment history|professional experience)\b/i',
            'Education' => '/\b(education|academic background)\b/i',
            'Skills' => '/\b(skills|technical skills|core competencies)\b/i',
        ];
        $found = 0;
        foreach ($sections as $label => $pattern) {
            if (preg_match($pattern, $text)) $found++;
            else $add('suggestion', $label . ' section not detected', 'Use a conventional heading if this information applies to your background.', 4, $label, 'No matching conventional heading was found.');
        }
        if (preg_match('/\b(?:experience|education|skills|projects|certifications)\b/i', $text)) {
            $add('info', 'Common section headings found', 'Standard section names can make your CV easier for parsers and recruiters to scan.', 0, 'Section headings', 'At least one common heading appears in the extracted text.');
        }
        if (preg_match('/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{4}\b/i', $text)
            && preg_match('/\b(?:19|20)\d{2}\s*[-–]\s*(?:19|20)\d{2}\b/', $text)) {
            $add('suggestion', 'Date styles may be mixed', 'Use one date style throughout, such as “Jan 2023 – Mar 2025”.', 2, 'Dates', 'Month-and-year dates and year ranges both appear in the CV.');
        }
        $bulletCount = preg_match_all('/(?:^|\s)(?:[•●▪◦]|[-*])\s+\S/u', $text);
        if ($bulletCount < 2) $add('suggestion', 'Few bullet points detected', 'Use concise bullets for accomplishments and responsibilities where they improve readability.', 3, 'Experience and projects', 'Found ' . (int)$bulletCount . ' bullet marker(s).');
        if (preg_match('/\b(?:\d+%|\$\s?\d[\d,.]*|\b\d+\s+(?:users|customers|projects|people|hours|days))\b/i', $text)) {
            $add('info', 'Measurable results found', 'Specific scope or outcomes can help readers understand the impact of your work.', 0, 'Experience and projects', 'A percentage, currency amount, or count of people, projects, users, customers, hours, or days was found.');
        } else {
            $add('suggestion', 'No measurable results detected', 'Where accurate, add evidence such as scale, time saved, revenue, quality, or delivery outcomes.', 2, 'Experience and projects', 'No common numeric outcome pattern was detected.');
        }
        if ($possibleLayoutArtifacts) $add('warning', 'Possible column or table extraction artifacts', 'The extracted text contains separators that may indicate a complex layout. Review the source document reading order.', 10, 'Document layout', 'Column separators or repeated tabs were found in the source text.');
        if ($splitDateEvidence !== '') $add('warning', 'Possible split numeric date', 'A numeric date fragment ends without its year. Review the date in the editor before exporting.', 6, 'Dates', 'Incomplete date fragment found: ' . $splitDateEvidence);
        if (preg_match('/\b(?:photo|headshot|references available upon request)\b/i', $text)) $add('suggestion', 'Optional content detected', 'A photo or a references statement may take space; check whether the target market expects it.', 1);

        $keywordResult = ['checked' => false, 'matched' => [], 'missing' => []];
        if (trim($jobDescription) !== '') {
            $keywords = $this->topTerms($jobDescription, 18);
            $haystack = mb_strtolower($text);
            foreach ($keywords as $keyword) {
                if (mb_strpos($haystack, $keyword) !== false) $keywordResult['matched'][] = $keyword;
                else $keywordResult['missing'][] = $keyword;
            }
            $keywordResult['checked'] = true;
            if ($keywordResult['missing']) {
            $add('info', 'Job description terms to review', 'Review the missing terms below. Add one only when it accurately describes your skills or experience.', 0, 'Job description comparison', implode(', ', array_slice($keywordResult['missing'], 0, 8)));
            } else {
            $add('info', 'Common job description terms found', 'The selected terms appear in the CV text; this is a text comparison, not an ATS ranking.', 0, 'Job description comparison', implode(', ', array_slice($keywordResult['matched'], 0, 8)));
            }
        }

        return [
            'score' => $score,
            'score_label' => 'heuristic review score',
            'summary' => $found . ' of ' . count($sections) . ' common sections detected. ATS products vary, so this score is not a hiring prediction or compatibility guarantee.',
            'findings' => $findings,
            'keywords' => $keywordResult,
        ];
    }

    private function topTerms(string $text, int $limit): array
    {
        $words = preg_split('/[^\p{L}\p{N}+#.]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true) || preg_match('/^\d+$/', $word)) continue;
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, $limit);
    }
}
