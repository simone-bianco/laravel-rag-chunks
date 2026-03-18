<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Unit\Support;

use SimoneBianco\LaravelRagChunks\Support\TextSanitizer;
use SimoneBianco\LaravelRagChunks\Tests\TestCase;

class TextSanitizerTest extends TestCase
{
    public function test_it_sanitizes_null_and_empty_strings()
    {
        $this->assertEquals('', TextSanitizer::sanitizeForJson(null));
        $this->assertEquals('', TextSanitizer::sanitizeForJson(''));
    }

    public function test_it_removes_control_characters()
    {
        // 0x00 is NULL, 0x08 is Backspace, 0x0B is VT, 0x0C is FF
        $text = "hello" . chr(0) . "world" . chr(8) . "test" . chr(11) . "again" . chr(12);
        
        $sanitized = TextSanitizer::sanitizeForJson($text);
        
        $this->assertEquals("helloworldtestagain", $sanitized);
    }

    public function test_it_preserves_valid_whitespace_characters()
    {
        // \n \r \t should be preserved
        $text = "hello\nworld\rtest\tagain";
        
        $sanitized = TextSanitizer::sanitizeForJson($text);
        
        $this->assertEquals("hello\nworld\rtest\tagain", $sanitized);
    }

    public function test_it_handles_invalid_utf8_gracefully()
    {
        // 0xFF is an invalid UTF-8 byte
        $text = "hello" . chr(255) . "world";
        
        $sanitized = TextSanitizer::sanitizeForJson($text);
        
        // chr(255) in invalid UTF-8 will be converted to ? by mb_convert_encoding
        $this->assertEquals("hello?world", $sanitized);
    }
}
