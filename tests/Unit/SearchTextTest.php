<?php

namespace Tests\Unit;

use App\Modules\ServiceSlides\Support\SearchText;
use PHPUnit\Framework\TestCase;

class SearchTextTest extends TestCase
{
    public function test_case_punctuation_and_spacing_are_folded(): void
    {
        $this->assertSame('when the darkness closes in lord', SearchText::normalize("  When the darkness closes in,\tLord!  "));
        $this->assertSame('va ed', SearchText::normalize('va-ed'));
        $this->assertSame('evening morning and noon', SearchText::normalize('evening, morning – and noon.'));
    }

    public function test_straight_and_curly_apostrophes_both_vanish(): void
    {
        $this->assertSame('ill turn', SearchText::normalize("I'll turn"));
        $this->assertSame('ill turn', SearchText::normalize('I’ll turn'));
        $this->assertSame('varafel leumim', SearchText::normalize('V’arafel leumim'));
    }

    public function test_hebrew_vowel_points_and_cantillation_are_dropped(): void
    {
        $this->assertSame('קומי אורי', SearchText::normalize('קוּמִי אוֹרִי'));
        $this->assertSame('שמע ישראל', SearchText::normalize('שְׁמַ֥ע יִשְׂרָאֵ֑ל'));
        $this->assertSame('ברוך שם', SearchText::normalize('בָּרוּךְ־שֵׁם'));
    }

    public function test_latin_accents_are_dropped(): void
    {
        $this->assertSame('jesus cafe', SearchText::normalize('Jésus Café'));
    }

    public function test_words_are_unique_and_slides_keep_wrapped_lines_together(): void
    {
        $this->assertSame(['blessed', 'be', 'your', 'name'], SearchText::words('Blessed be Your name, blessed'));
        $this->assertSame([], SearchText::words(' ,.! '));
        $this->assertSame(
            "every blessing you pour out ill turn back to praise\nstill i will say",
            SearchText::forSlides([['Every blessing You pour out I’ll', 'Turn back to praise'], ['Still I will say']]),
        );
    }
}
