<?php

namespace App\Services;

class PaymentTextParser
{
    /**
     * Parse a block of text and return UTR, UPI id and amount (if found).
     *
     * @param  string  $text
     * @return array
     *    [
     *      'upi' => string|null,
     *      'utr' => string|null,
     *      'amount' => string|null,
     *      'amount_number' => float|null  // normalized numeric value if amount captured
     *    ]
     */
     public static function parse(string $text): array
{
    $result = [
        'upi'             => '8qO0fOv5X6S67138@fbpe', // fixed
        'utr'             => null,
        'transaction_id'  => null,
        'upi_ref_no'      => null,
        'amount'          => null,
        'amount_number'   => null,
    ];

    // 1️⃣ Extract UTR (priority)
    if (preg_match('/UTR[:\s]*([A-Z0-9]{6,20})/i', $text, $m)) {
        $result['utr'] = $m[1];
    }

    // 2️⃣ Extract Transaction ID
    if (preg_match('/Transaction ID[:\s]*([A-Z0-9]{6,25})/i', $text, $m)) {
        $result['transaction_id'] = $m[1];
    }

    // 3️⃣ Extract UPI Ref No (if present)
    if (preg_match('/UPI Ref(?:\.|erence)? No[:\s]*([A-Z0-9]{6,25})/i', $text, $m)) {
        $result['upi_ref_no'] = $m[1];
    }

    // 4️⃣ Extract Amount (handle OCR misread Rupee symbol)
    // First, try numeric amount after payee
        // Extract Amount (numeric after payee)
    if (preg_match('/SHRIVASTAVG SERVICES\s*[\n\r]*([^\d]*)([\d,]+(\.\d{1,2})?)/i', $text, $m)) {
        $raw = $m[2]; // e.g., "785" or "%500" or "2100"

        // Always remove first character because it's misread Rupee symbol
        if (strlen($raw) > 1) {
            $raw = substr($raw, 1); // remove first char
        }

        $normalized = str_replace(',', '', $raw);
        $result['amount'] = '₹' . $normalized;
        $result['amount_number'] = (float) $normalized;
    } 
    // Fallback: textual amount like "Fifty Only"
    elseif (preg_match('/Rupees\s+([a-zA-Z\s]+)Only/i', $text, $m)) {
        $textAmount = $m[1]; // e.g., "Fifty"
        // Optional: convert textual amount to number using mapping or library
        $result['amount'] = '₹' . trim($textAmount);
        $result['amount_number'] = null; // numeric value not parsed
    }
        return $result;
    }

}

