<?php
// config/LibraryHelper.php

// ---- spl_autoload_register ----
spl_autoload_register(function(string $class_name): void {
    $file = __DIR__ . '/' . $class_name . '.php';
    if (file_exists($file)) require_once $file;
});

// ---- Base class: Book ----
class Book {
    private int    $id;
    private string $title;
    private string $author;
    private int    $available_copies;
    private int    $total_copies;
    public  string $category;

    public function __construct(
        int $id, string $title, string $author,
        int $total_copies, string $category = 'General'
    ) {
        $this->id               = $id;
        $this->title            = $title;
        $this->author           = $author;
        $this->total_copies     = $total_copies;
        $this->available_copies = $total_copies;
        $this->category         = $category;
    }

    public function __get(string $name): mixed {
        $allowed = ['id','title','author','available_copies','total_copies'];
        return in_array($name, $allowed) ? $this->$name : null;
    }

    public function __set(string $name, mixed $value): void {
        if ($name === 'available_copies') {
            $this->available_copies = max(0, min((int)$value, $this->total_copies));
        }
    }

    public function __toString(): string {
        return "{$this->title} by {$this->author} ({$this->available_copies}/{$this->total_copies})";
    }

    public function __clone(): void {
        $this->available_copies = $this->total_copies; // reset on clone
    }

    public function getAvailabilityLabel(): string {
        $pct = $this->total_copies > 0
            ? round(($this->available_copies / $this->total_copies) * 100) : 0;
        switch (true) {
            case $pct === 0:   return 'Out of Stock';
            case $pct <= 25:   return 'Very Low';
            case $pct <= 50:   return 'Low';
            case $pct <= 75:   return 'Available';
            default:           return 'Well Stocked';
        }
    }

    public function validateField(string $field, mixed $value): string {
        if (is_null($value))        return "$field is null";
        elseif (is_bool($value))    return "$field is bool";
        elseif (is_int($value))     return "$field is int: $value";
        elseif (is_float($value))   return "$field is float: $value";
        elseif (is_numeric($value)) return "$field is numeric: $value";
        else                        return "$field is string: $value";
    }

    public function analyseDescription(string $text): array {
        return [
            'word_count' => str_word_count($text),
            'has_isbn'   => strpos($text, 'ISBN') !== false,
            'preview'    => substr($text, 0, 60),
        ];
    }
}

// ReferenceBook extends Book, overrides __toString
class ReferenceBook extends Book {
    private string $edition;

    public function __construct(
        int $id, string $title, string $author,
        int $total_copies, string $edition = '1st Edition'
    ) {
        parent::__construct($id, $title, $author, $total_copies, 'Reference');
        $this->edition = $edition;
    }

    public function __toString(): string {
        return parent::__toString() . " [{$this->edition}]";
    }
}

// ---- BookCSVImporter ----
class BookCSVImporter {
    private array $rows   = [];
    private array $errors = [];

    public function __construct(string $csv_content) {
        $lines = explode("\n", trim($csv_content));
        foreach ($lines as $i => $line) {
            if ($i === 0 || empty(trim($line))) continue;
            $cols = str_getcsv($line); // str_getcsv
            if (count($cols) < 4) { $this->errors[] = "Row ".($i+1).": not enough columns."; continue; }
            $this->rows[] = ['title'=>trim($cols[0]),'author'=>trim($cols[1]),'isbn'=>trim($cols[2]),'copies'=>trim($cols[3])];
        }
    }

    public function validateRows(): array {
        $valid = [];
        foreach ($this->rows as $i => $row) {
            if (strcmp($row['title'], 'SAMPLE') === 0) { // strcmp
                $this->errors[] = "Row ".($i+2).": placeholder title."; continue;
            }
            if (strcasecmp($row['author'], 'unknown') === 0) { // strcasecmp
                $this->errors[] = "Row ".($i+2).": unknown author."; continue;
            }
            if (strpos($row['isbn'], ' ') !== false) { // strpos
                $this->errors[] = "Row ".($i+2).": ISBN has spaces."; continue;
            }
            if (!is_numeric($row['copies']) || (int)$row['copies'] < 1) { // is_numeric
                $this->errors[] = "Row ".($i+2).": invalid copies."; continue;
            }
            if (str_word_count($row['title']) < 1) { // str_word_count
                $this->errors[] = "Row ".($i+2).": empty title."; continue;
            }
            $valid[] = $row;
        }
        return $valid;
    }

    public function getErrors(): array { return $this->errors; }

    public function __toString(): string {
        return "BookCSVImporter: {$this->rowCount()} rows, ".count($this->errors)." errors.";
    }

    public function rowCount(): int { return count($this->rows); }
}
?>