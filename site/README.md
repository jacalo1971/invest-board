# Family Stock School site

Source of https://htv.studio/invest (uploaded to cPanel by hand; the data in ../data is updated automatically).

- `index.html`: the page.
- `api/`: PHP for accounts, My Stocks, Ask Coach and the admin panel. Goes in `htv.studio/invest/api/`.
- `config.sample.php`: copy to `/home4/htvstudio/invest_private/config.php` (outside the website) and fill in the database password, the Anthropic API key and the FMP key. Never commit the real config.
