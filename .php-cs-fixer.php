<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP83Migration' => true,
        '@Symfony' => true,
        '@Symfony:risky' => false,
        'align_multiline_comment' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_before_statement' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'heredoc_to_nowdoc' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_extra_blank_lines' => ['tokens' => ['break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block']],
        'no_null_property_initialization' => true,
        'no_superfluous_elseif' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_class_elements' => false,
        'ordered_imports' => true,
        'phpdoc_add_missing_param_annotation' => true,
        // One space, never padding: aligned columns turn every added line into a whitespace diff.
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_types_order' => true,
        'semicolon_after_instruction' => true,
        'single_line_comment_style' => true,
        'strict_param' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder)
;
