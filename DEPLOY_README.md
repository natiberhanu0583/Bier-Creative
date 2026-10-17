# ብዕር ክርኤቲቭ — ሰርቨር ላይ መጫኛ (ፈጣን ዝርዝር)

1. **ፋይሎች:** የዚፑን ውስጥ ያሉትን ሁሉ (index.html፣ backend/፣ admin/ …) በቀጥታ ወደ `public_html` ይስቀሉ (አቃፊ ሳይጨምሩ)።
2. **ዳታቤዝ:** cPanel → MySQL Databases → ዳታቤዝ + ተጠቃሚ ይፍጠሩ (All Privileges) → phpMyAdmin → **Import** → `backend/schema.sql`።
3. **`backend/config.php`** ውስጥ ይቀይሩ: `DB_NAME`፣ `DB_USER`፣ `DB_PASS` (በ `: 'yourcpanel_…'` ያሉት የሆስት ዋጋዎች)፣ `APP_SECRET` (ረጅም ራንደም ጽሁፍ)።
4. **የባለቤት የይለፍ ቃል:** መጀመሪያ `https://biercreative.com/admin/` ገብተው `alex` / `123456` በመጠቀም ወዲያው የይለፍ ቃል ይቀይሩ (Profile)።
5. **AI ቻት (አማራጭ):** `config.php` → `ANTHROPIC_API_KEY` ላይ እውነተኛ key ያስገቡ። ካላስገቡ ቦቱ በራሱ የዕውቀት ክፍል ይሰራል።
6. **HTTPS:** SSL ያስነሱ (cPanel → SSL/TLS / AutoSSL)። PWA እና ድምጽ በHTTPS ይሻላሉ።
7. **ፈተና:** ድህረ ገጹን ይክፈቱ → ቻት → አንድ ሙከራ ኦርደር ይላኩ → አድሚን ውስጥ ይታያል? ከዚያ ሙከራውን ይሰርዙ።
8. **የድሮ ካሼ:** ከዚህ በፊት የነበረውን ስሪት ከተካችሁ Ctrl+F5።

ማስታወሻ: `ALLOWED_ORIGIN` ነባሪ `*` ነው። ድህረ ገጹ እና አድሚኑ ተመሳሳይ ዶሜይን ላይ ስለሆኑ ችግር የለውም፤ ከፈለጉ ወደ `https://biercreative.com` ይቀይሩ።

## ደህንነት (ተካትቷል)
- JS/CSS ተጨምቆ እና ተቀይሮ (minified) ተሰጥቷል — ኮዱ ለማንበብ አስቸጋሪ ነው። (ማስታወሻ: ብራውዘር የሚያወርደውን ኮድ ሙሉ በሙሉ መደበቅ አይቻልም፤ ሚስጥር ሁሉ (API key፣ የዳታቤዝ መረጃ፣ ህጎች) በPHP ሰርቨር ላይ ብቻ ነው።)
- ሌላ ድረ ገጽ የእርስዎን አድሚን session መጠቀም አይችልም (CORS ተዘግቷል)፣ cookie: HttpOnly + SameSite + (HTTPS ላይ) Secure።
- የሎግኢን ሙከራ ገደብ: 8 ያልተሳኩ ሙከራዎች በ15 ደቂቃ → ጊዜያዊ እገዳ (በራሱ ይነሳል)።
- የሰራተኞች የይለፍ ቃል (ባለቤቱ የሚያየው) በዳታቤዝ ውስጥ ኢንክሪፕት ተደርጎ ይቀመጣል — **`APP_SECRET`ን ከመጀመሪያው ያስቀምጡ እና በኋላ አይቀይሩት** (ከተቀየረ የሚታዩት የድሮ የይለፍ ቃሎች ባዶ ይሆናሉ፤ ሰራተኞች ራሳቸው ይገባሉ)።
- `.htaccess`: HTTPS አስገዳጅ፣ HSTS፣ CSP፣ X-Frame-Options፣ config/db ፋይሎች በቀጥታ መክፈት ተከልክሏል (Apache/cPanel ላይ ይሰራል፤ Nginx ከሆነ እኩያ ደንቦች ያስፈልጋሉ)።
