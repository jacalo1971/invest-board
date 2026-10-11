<?php
// Family Stock School configuration.
// Copy this file to config.php in the same private folder (outside public_html / htv.studio)
// and fill in the values. Never put this file inside the website folder.

return [
    // MySQL database created in cPanel > MySQL Database Wizard
    'db_dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_invest;charset=utf8mb4',
    'db_user' => 'CPANELUSER_invest',
    'db_pass' => 'PASTE_DATABASE_PASSWORD_HERE',

    // Anthropic API key from console.anthropic.com (starts with sk-ant-)
    'anthropic_key'   => 'PASTE_ANTHROPIC_KEY_HERE',
    'anthropic_model' => 'claude-haiku-5-5',

    // Financial Modeling Prep key (FMP dashboard > API Keys), used for prices of stocks outside the watchlist
    'fmp_key' => 'PASTE_FMP_KEY_HERE',

    // Limits
    'questions_per_day' => 20,   // per person
    'max_answer_tokens' => 700,

    // Public data (same files the page uses)
    'data_base' => 'https://raw.githubusercontent.com/jacalo1971/invest-board/main/data/',
];
