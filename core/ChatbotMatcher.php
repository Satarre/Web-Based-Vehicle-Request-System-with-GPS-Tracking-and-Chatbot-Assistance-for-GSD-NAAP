<?php
declare(strict_types=1);
/**
 * core/ChatbotMatcher.php — pure, DB-free keyword matcher for the GSD Assistant.
 *
 * Each FAQ has a canonical `prompt_keyword` plus optional `keywords` synonyms
 * (|-separated). A question is normalized (lowercased, punctuation -> space,
 * collapsed/trimmed whitespace) and scored per phrase with:
 *   - character similarity (similar_text)
 *   - word-overlap ratio
 *   - a whole-word substring boost (word boundaries, not raw substring)
 * Short single-token phrases (<= 4 chars) only match on an exact whole word so
 * e.g. "car" never matches "care"/"carpet". Keep it pure so tests can run
 * without a database; api/chatbot.php delegates to it.
 */

final class ChatbotMatcher
{
    public const THRESHOLD   = 0.45;
    public const OVERLAP_MIN = 0.5;

    /** Guides about the request form whose answers may include form feedback. */
    public const FORM_CHECK_KEYWORDS = ['required fields', 'schedule rules'];

    /**
     * Form-completeness warnings, only for the guides that concern the form.
     * Returns [] for any other keyword so normal FAQs never dump a missing-
     * field list on the requester.
     *
     * @param array  $form    request-form state keyed by field name
     * @param string $keyword matched FAQ canonical keyword
     * @return string[]
     */
    public static function formWarnings(array $form, string $keyword): array
    {
        return array_map(static fn(array $w): string => $w['message'], self::formWarningItems($form, $keyword));
    }

    /**
     * Same warnings with the form field they belong to, so the panel can offer
     * a "fix" button that focuses that input. `field` is null when the warning
     * is not tied to a single control.
     *
     * @return array<int, array{field: string|null, message: string}>
     */
    public static function formWarningItems(array $form, string $keyword): array
    {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        if ($form === [] || !in_array($keyword, self::FORM_CHECK_KEYWORDS, true)) {
            return [];
        }

        $items = [];

        if ($keyword === 'required fields') {
            $required = [
                'passenger_name'      => 'First name',
                'id_number'           => 'ID number',
                'place_to_visit'      => 'Place to visit',
                'purpose'             => 'Purpose of travel',
                'scheduled_departure' => 'Scheduled departure',
                'scheduled_return'    => 'Scheduled return',
            ];
            foreach ($required as $k => $label) {
                if (!isset($form[$k]) || trim((string) $form[$k]) === '') {
                    $items[] = ['field' => $k, 'message' => "Missing: {$label}."];
                }
            }
        }

        if (!empty($form['scheduled_departure']) && !empty($form['scheduled_return'])) {
            $dep = strtotime((string) $form['scheduled_departure']);
            $ret = strtotime((string) $form['scheduled_return']);
            if ($dep !== false && $ret !== false && $ret <= $dep) {
                $items[] = [
                    'field'   => 'scheduled_return',
                    'message' => 'Scheduled return must be after scheduled departure.',
                ];
            }
        }

        return $items;
    }

    /**
     * Normalize for comparison: lowercase, non-alphanumerics -> space,
     * collapse whitespace, trim.
     */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }

    /** Unique, non-empty tokens of a normalized string. */
    public static function words(string $n): array
    {
        return array_values(array_unique(array_filter(explode(' ', $n))));
    }

    /** Every searchable phrase for one FAQ: canonical keyword + synonyms. */
    public static function phrases(array $faq): array
    {
        $list = [(string) ($faq['prompt_keyword'] ?? '')];
        foreach (preg_split('/[|\r\n]+/', (string) ($faq['keywords'] ?? '')) as $s) {
            $s = trim($s);
            if ($s !== '') {
                $list[] = $s;
            }
        }
        return $list;
    }

    /** Whole-word (token-boundary) containment on normalized strings. */
    private static function hasWholeWord(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }
        return preg_match('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/', $haystack) === 1;
    }

    /**
     * Score one normalized phrase against a normalized question (0..1).
     */
    public static function phraseScore(string $q, array $qw, string $phrase): float
    {
        $pn = self::norm($phrase);
        if ($pn === '') {
            return 0.0;
        }
        $pw = self::words($pn);

        // Short single-token guard: exact whole-word match only (kills "car" vs "care").
        if (count($pw) === 1 && mb_strlen((string) $pw[0]) <= 4) {
            return self::hasWholeWord($q, $pn) ? 1.0 : 0.0;
        }

        $overlap = 0.0;
        if ($pw !== []) {
            $overlap = count(array_intersect($qw, $pw)) / count($pw);
        }
        if (self::hasWholeWord($q, $pn)) {
            $overlap = 1.0;
        }
        // Require lexical evidence: without MORE than half of the phrase's words
        // (or the whole phrase) present, character similarity alone is too noisy.
        // Strict ">" (not ">=") so a 2-word phrase sharing one generic word
        // (e.g. "ticket" in "cancel ticket" vs "how can i submit my trip ticket")
        // scores 0 instead of flooring at 0.5 and stealing the match.
        if ($overlap <= self::OVERLAP_MIN) {
            return 0.0;
        }
        similar_text($q, $pn, $pct);
        return max($pct / 100.0, $overlap);
    }

    /**
     * Best matching FAQ for a question.
     *
     * @param array $faqs rows with prompt_keyword + keywords (may include more fields)
     * @return array{faq: array, score: float, phrase: string}|null
     */
    public static function best(array $faqs, string $question, float $threshold = self::THRESHOLD): ?array
    {
        $q = self::norm($question);
        if ($q === '') {
            return null;
        }
        $qw = self::words($q);

        $best      = null;
        $bestScore = 0.0;
        $bestWords = 0;
        foreach ($faqs as $faq) {
            foreach (self::phrases($faq) as $phrase) {
                $score = self::phraseScore($q, $qw, $phrase);
                if ($score <= 0.0) {
                    continue;
                }
                // On a tie prefer the more specific (longer) phrase instead of
                // silently keeping whichever FAQ iterates first.
                $words = count(self::words(self::norm($phrase)));
                if ($score > $bestScore || ($score === $bestScore && $words > $bestWords)) {
                    $bestScore = $score;
                    $bestWords = $words;
                    $best      = ['faq' => $faq, 'score' => $score, 'phrase' => $phrase];
                }
            }
        }

        if ($best === null || $bestScore < $threshold) {
            return null;
        }
        $best['score'] = round($bestScore, 2);
        return $best;
    }
}