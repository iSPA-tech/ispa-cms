<?php

declare(strict_types=1);

namespace Cms\Content\Services\Agentic;

/**
 * Text normalization for the answer corpus search: lower case, Vietnamese diacritics folded, punctuation
 * dropped, and question words that carry no topic (Vietnamese and English stop words) removed.
 *
 * Stop words are checked on the accented word first, so "là" (is) is dropped but "lá" (leaf) is kept; an unaccented
 * word is dropped when it matches a folded stop word, because the reader typed without diacritics.
 */
final class AgentAnswerSearchText
{
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a',
        'ẩ' => 'a', 'ẫ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
        'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e',
        'ể' => 'e', 'ễ' => 'e',
        'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o',
        'ổ' => 'o', 'ỗ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u',
        'ử' => 'u', 'ữ' => 'u',
        'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
        'đ' => 'd',
    ];

    /** Accented Vietnamese function and question words. */
    private const STOP_ACCENTED = [
        'là', 'gì', 'nào', 'thế', 'sao', 'bao', 'nhiêu', 'không', 'có', 'của', 'và', 'các', 'những', 'một', 'cho',
        'với', 'được', 'thì', 'mà', 'ở', 'tại', 'khi', 'nên', 'này', 'đó', 'như', 'để', 'từ', 'về', 'hãy', 'xin',
        'hỏi', 'ạ', 'nhé', 'ơi', 'tôi', 'mình', 'bạn', 'em', 'anh', 'chị', 'vậy', 'à', 'ư', 'hả', 'chứ', 'đã', 'đang',
        'sẽ', 'rồi', 'cũng', 'thì', 'nếu', 'hay', 'hoặc', 'bị', 'làm', 'cần', 'phải', 'nhất', 'lắm', 'rất', 'ai',
    ];

    /** English function and question words. */
    private const STOP_ENGLISH = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'what', 'which', 'who', 'how', 'why', 'when', 'where',
        'do', 'does', 'did', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'with', 'can', 'i', 'you', 'my', 'it',
        'this', 'that', 'should', 'much', 'many', 'about', 'please',
    ];

    /**
     * Distinct search terms of a question (folded), in first-seen order.
     *
     * @return list<string>
     */
    public function terms(string $question): array
    {
        $terms = [];
        foreach ($this->rawWords($question) as $word) {
            $folded = $this->fold($word);
            if ($this->isStopWord($word, $folded) || in_array($folded, $terms, true)) {
                continue;
            }
            $terms[] = $folded;
        }

        return $terms;
    }

    /**
     * Folded word set of a text, for membership checks.
     *
     * @return array<string, true>
     */
    public function words(string $text): array
    {
        $words = [];
        foreach ($this->rawWords($text) as $word) {
            $words[$this->fold($word)] = true;
        }

        return $words;
    }

    public function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
    }

    /** @return list<string> Lower-case words: letters and digits only. */
    private function rawWords(string $text): array
    {
        $text = mb_strtolower(strip_tags($text), 'UTF-8');
        // Thousand separators keep numbers whole ("1.500" -> "1500").
        $text = (string) preg_replace('/(?<=\d)[.,](?=\d{3}\b)/u', '', $text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    private function isStopWord(string $word, string $folded): bool
    {
        static $foldedStops = null;
        $foldedStops ??= array_fill_keys(array_map(fn (string $stop): string => $this->fold($stop), self::STOP_ACCENTED), true);

        if (in_array($word, self::STOP_ACCENTED, true) || in_array($word, self::STOP_ENGLISH, true)) {
            return true;
        }

        // Typed without diacritics: drop when it matches a folded stop word.
        return $word === $folded && isset($foldedStops[$folded]);
    }
}
