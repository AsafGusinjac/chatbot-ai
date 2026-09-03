# D-Store Chatbot Deployment

> **Tražiš šta treba da uradi developer na strani sajta?** To je u
> [`docs/developer-todo.md`](developer-todo.md) — kratko, bez istorijata.
> Ovaj fajl (`deployment.md`) je radni dnevnik/istorijat odluka za nastavak
> rada na kodu, ne za direktno slanje developeru.

## Recommended setup

Use three environments:

1. Local development on XAMPP
   - Used by the developer.
   - Mock AI can stay enabled here.
   - Catalog can be refreshed manually with `tools/sync_catalog.php`.

2. Staging server
   - A separate folder or subdomain, for example `https://digitalis.ba/chatbot-test/`.
   - Uses a separate MySQL database or at least separate chatbot tables.
   - Used for testing the real OpenAI key, product cards and add-to-cart button.
   - The public website should not load this until the boss approves it.

3. Production server
   - Final location, for example `https://digitalis.ba/chatbot/`.
   - `use_mock_ai` must be `false`.
   - `openai_key` must contain the production OpenAI API key.
   - `allowed_origins` should include only real Digitalis/D-Store domains.

## Catalog sync

The live chat does not call the Digitalis API on every customer message.

Only this script calls the Digitalis API:

```bat
C:\xampp\php\php.exe tools\sync_catalog.php
```

The script copies brands, categories, subcategories and products into local
MySQL. Customer questions search this local database.

For these storefronts, prices and stock change often, so run sync every 2
hours. The script has a lock file; if one sync is still running when the next
cron starts, the new run exits cleanly instead of overlapping.

## Windows Task Scheduler example

If the server is Windows/XAMPP:

- Program:

```text
C:\xampp\php\php.exe
```

- Arguments:

```text
C:\path\to\chatbot\tools\sync_catalog.php
```

- Start in:

```text
C:\path\to\chatbot
```

Run every 2 hours.

## Linux cron example

If the server is Linux:

```cron
0 */2 * * * /usr/bin/php /var/www/chatbot/tools/sync_catalog.php >> /home/falcomba/chatbot-sync.log 2>&1
```

## What to ask the server/admin team

Ask for:

- PHP 7.4 or newer.
- MySQL or MariaDB database for the chatbot.
- A database user limited to that chatbot database.
- A private writable folder for `data/ratelimit`.
- Ability to run `tools/sync_catalog.php` every 2 hours.
- HTTPS URL for the API endpoint, for example `/chatbot/endpoint/chat.php`.

The website only needs one script tag when ready:

```html
<script src="https://digitalis.ba/chatbot/public/embed.js"
        data-endpoint="https://digitalis.ba/chatbot/endpoint/chat.php" defer></script>
```

Widget po defaultu nakon 10 sekundi na desktopu prikaže mali klikabilni pozdrav
pored ikonice. Ne otvara cijeli chat automatski. Na mobitelu se taj pozdrav ne
prikazuje. `data-auto-open-delay` i `data-teaser` su samo opcionalni override
ako vlasnik chatbot deploymenta želi drugačije ponašanje.

Na product/detail stranici developer može poslati kontekst artikla:

```html
<script>
  window.DstoreChat = window.DstoreChat || function () {
    (window.DstoreChat.q = window.DstoreChat.q || []).push(arguments);
  };
</script>
<script src="https://digitalis.ba/chatbot/public/embed.js"
        data-endpoint="https://digitalis.ba/chatbot/endpoint/chat.php" defer></script>
<script>
  DstoreChat('product', {
    id: 62577,
    name: 'Naziv proizvoda koji je otvoren',
    url: window.location.href
  });
</script>
```

Kad kupac otvori chat na toj stranici, widget prikaže par brzih pitanja za
taj artikal bez OpenAI poziva. Ako kupac postavi drugo pitanje, chatbot i dalje
odgovara normalno kao i prije.

## Go-live checklist

- Run `db/schema.sql` on the production database.
- Fill `config.local.php` on the server.
- Set `use_mock_ai` to `false`.
- Add the OpenAI API key to `openai_key`.
- Set production rate limits in `config.local.php`:

```php
'burst_rate_limit_max' => 6,
'burst_rate_limit_window' => 60,
'rate_limit_max' => 20,
'rate_limit_window' => 300,
'visitor_daily_limit' => 80,
'visitor_daily_window' => 86400,
'ip_daily_limit' => 120,
'ip_daily_window' => 86400,
'global_daily_limit' => 2000,
'global_daily_window' => 86400,
'max_message_length' => 1500,
'max_messages_per_conversation' => 8,
'ai_user_daily_limit' => 30,
'ai_user_daily_window' => 86400,
'ai_global_daily_limit' => 500,
'ai_global_daily_window' => 86400,
'max_message_urls' => 2,
'max_message_newlines' => 20,
'max_repeated_character_run' => 80,
'max_repeated_word_run' => 35,
'canned_replies_file' => __DIR__ . '/data/canned_replies.json',
'feedback_rate_limit_max' => 10,
'feedback_rate_limit_window' => 3600,
```

- Run `tools/sync_catalog.php` once manually.
- Test `endpoint/chat.php` from the staging page.
- Test product cards, image loading and add-to-cart on the real webshop.
- Add the embed script to the real page template.

## cPanel-only protection

If the domain is not behind Cloudflare, keep protection in PHP and Apache:

- Keep `.htaccess` uploaded in the chatbot root. It disables directory listing
  and blocks direct browser access to config, SQL, ZIP/backups and debug/test
  PHP files.
- Keep folder permissions at `0755` and PHP/JS/CSS files at `0644`.
- Do not leave debug files online after testing.
- Keep `allowed_origins` strict in `config.local.php`; include only the real
  storefront domains that may embed the widget.
- Keep rate limits and AI budget limits enabled:

```php
'burst_rate_limit_max' => 6,
'burst_rate_limit_window' => 60,
'rate_limit_max' => 20,
'rate_limit_window' => 300,
'visitor_daily_limit' => 80,
'ip_daily_limit' => 120,
'global_daily_limit' => 2000,
'max_message_length' => 1500,
'max_messages_per_conversation' => 8,
'ai_user_daily_limit' => 30,
'ai_global_daily_limit' => 500,
```

## Multi-site produkcijska spremnost (živi checklist)

Svaki sajt = zaseban deployment ove iste kodne baze (svoj folder/server, svoj
`config.local.php`, svoj bot). Uloge:

- **digitalis.ba** — VELEPRODAJA, ima login za veleprodajne kupce.
- **dstore.ba** — MALOPRODAJA, dijeli katalog/bazu sa digitalis.ba, nema login.
- **zed.hr** — VELEPRODAJA.
- **optibox.rs** — VELEPRODAJA.

Ovo je radni dokument — dopunjavati kako se stvari razjasne ili promijene,
ne brisati stavke dok stvarno nisu riješene.

### 🟢 Login-gated pristup (Digitalis, zed.hr, optibox.rs) — riješeno na Digitalis-u 2026-08-25

Ulogovani veleprodajni korisnici (na sva tri veleprodajna sajta —
digitalis.ba, zed.hr, optibox.rs) treba da vide dodatne artikle koje
maloprodaja ne vidi, i stanje na lageru. Bot to SMIJE pokazati samo ako je
osoba stvarno ulogovana — nikad na osnovu onoga što klijent (browser) samo
tvrdi.

**Pravilo za nabavne cijene, dogovoreno 2026-08-24: AI chat NIKAD ne
izgovara/piše nabavnu (kost) cijenu, čak ni ulogovanom korisniku.** Ako
neko pita za nabavnu cijenu, bot samo odgovori da tu informaciju može
vidjeti na sajtu kad je ulogovan na svoj account — ne šalje broj kroz chat
odgovor. Ovo pojednostavljuje taj dio: ne treba mehanizam da nabavna cijena
uopšte putuje kroz AI odgovor, samo treba prepoznati namjeru pitanja
(slično postojećem `installmentPurchaseAnswer()`/FAQ obrascu u
`ChatService.php`/`MockChatModel.php`) i vratiti fiksnu
poruku-preusmjerenje. Dodatni artikli i stanje na lageru za ulogovane
korisnike i dalje trebaju stvaran login-aware API pristup (ispod).

**Ispostavilo se da nam poseban developerov API uopšte nije trebao — i
ispravka 2026-08-25, poslije prve verzije ovog rješenja.** Prvobitni plan
(2026-08-24) je bio da developer napravi poseban login-aware API. Prva
zamjena za to (istog dana, 2026-08-25) čitala je stvaran login cookie sajta
(`sc_logged1`) direktno. **Korisnik je to odbio** — cookie-čitanje je
prekomplikovano/nepouzdano rješenje (zavisi od domene, HttpOnly statusa,
imena koje se može razlikovati po sajtu). Umjesto toga: **sajt sam, preko
svog JS-a, eksplicitno šalje šta god botu treba** — ID kupca, ime (ako
postoji), da li je veleprodajni kupac, boju, logo. Jedan poziv, na bilo
kojem sajtu, umjesto čitanja bilo čega sa strane.

Obrazac (isti kao Intercom/Google Analytics — "command queue stub", radi
bez obzira da li se pozove prije ili poslije `embed.js`):

```html
<script>
  window.DstoreChat = window.DstoreChat || function () {
    (window.DstoreChat.q = window.DstoreChat.q || []).push(arguments);
  };
</script>
<script src="https://digitalis.ba/chatbot/public/embed.js" data-endpoint="..." defer></script>
<script>
  DstoreChat('identify', {
    customerId: '12345',
    customerName: 'Ivan Ivić',
    isWholesaleCustomer: true,
    color: '#0064b4',
    logo: 'https://.../logo.svg'
  });
</script>
```

- [x] **Implementirano i uživo testirano 2026-08-25.** `embed.js`/`widget.js`
      izlažu `window.DstoreChat('identify', {...})`; `applyIdentify()` čuva
      `customerId`/`customerName`/`isWholesaleCustomer` i šalje ih sa SVAKIM
      zahtjevom (`customer_id`, `customer_name`, `wholesale_hint`), i uživo
      ažurira `--accent` boju i logo čak i ako se `identify()` pozove NAKON
      što je widget već izgrađen (npr. async login bez reload-a stranice).
      `endpoint/chat.php` više ne dira `$_COOKIE` uopšte — čita ta tri polja
      direktno iz JSON tijela i prosljeđuje ih kao `$visitor` niz u
      `ChatService::reply()`. `ChatService` čuva `wholesaleVerified`
      (isto kao prije, ista `ProductSearch` logika) i sad i `customerName`.
      Provjereno UŽIVO u pravom browseru (Shadow DOM, ista staza kao
      produkcija), oba puta: (1) `identify()` pozvan PRIJE nego što se
      `embed.js` učita (queue put) — artikal 6808 (`vp=1,mp=0`) se odmah
      pojavljuje pri prvoj poruci; (2) `identify()` pozvan NAKON što je
      widget već otvoren, sa `isWholesaleCustomer: false` i novom bojom —
      boja se uživo promijenila (`--accent` potvrđeno preko
      `getComputedStyle`), sljedeći zahtjev više NE sadrži
      `wholesale_hint`/`customer_id`/`customer_name` uopšte, artikal 6808
      nestaje. Payload zahtjeva provjeren direktno (patch-ovan `fetch`) u
      oba slučaja.
- [x] **Personalizacija imenom — riješeno istim mehanizmom 2026-08-25.**
      Više ne čeka developera — `customerName` iz istog `identify()` poziva
      se dodaje u `ChatService::contextSuffix()` (isto mjesto gdje se već
      dodaje "izvan radnog vremena" kontekst, poslije keširanog system
      prompta) kao kratka napomena AI-u da koristi ime prirodno. Tretirano
      striktno kao ime za prikaz, ne kao instrukcija — ograničeno na 80
      znakova i eksplicitno rečeno modelu da ignoriše bilo šta što liči na
      komandu unutar njega (isti nivo povjerenja kao bilo koja poruka
      korisnika — `identify()` može pozvati bilo čija JS konzola).
      Mock način rada (`MockChatModel`) ovo ne koristi (kanonski odgovori),
      pa se personalizacija vidi samo u pravom AI režimu.
- [ ] **zed.hr/optibox.rs:** isti kod, ništa dodatno da se konfiguriše —
      radi čim njihova strana pozove `DstoreChat('identify', ...)` sa
      `isWholesaleCustomer: true`. Jedino što ostaje: da developer/vlasnik
      tih sajtova stvarno DODA taj poziv na svoju stranicu kad zna da je
      kupac ulogovan (van našeg dometa — to je njihov kod).
- [x] **Da li NAŠ trenutni API pristup vraća te podatke — RIJEŠENO
      2026-08-25, VRAĆA.** Prvobitna provjera 2026-08-24 (dump svih polja
      preko cijelog kataloga, i `/products` liste i pojedinačnog
      `/products/{id}`) je pokazala dosljedno tačno 14 polja bez
      `is_vp`/`is_mp`. Korisnik je potom potvrdio da developer koristi ISTI
      token/endpoint i vidi ta polja — ponovljen identičan poziv (artikal
      45245, isti `digitalis_token`, isti `/products/{id}`) je ovog puta
      vratio `"is_vp":1,"is_mp":1`. API je, dakle, ažuriran na strani
      Digitalisa između te dvije provjere, u istoj sesiji — nikakva promjena
      tokena/endpointa nije bila potrebna s naše strane.
- [x] **Distribucija na cijelom katalogu — potvrđeno 2026-08-25**, preko
      `/products` liste, svih 10.483 artikla: 0 nedostaje oba polja;
      9.255 `vp=1,mp=1` (oba); 1.185 `vp=0,mp=1` (samo maloprodaja); 43
      `vp=1,mp=0` (samo veleprodaja). Stvaran, smislen signal za filtriranje.
- [x] **Implementirano 2026-08-25 — kolone i baza.** `is_vp`/`is_mp` kolone
      dodane u `products` (`db/schema.sql` za novu instalaciju; lijena
      migracija i u `tools/sync_catalog.php::ensureProductActionColumns()` i
      u `ProductSearch::ensureVisibilityColumns()`, isti obrazac kao za
      `is_action`). `tools/sync_catalog.php` mapira `is_vp`/`is_mp` iz feeda
      u upsert (default 1 kad feed red ne nosi polje, da ništa ne nestane
      slučajno).
- [x] **Korigovano 2026-08-25 — šta je zapravo "bazna" vidljivost.** Prva
      verzija je filtrirala digitalis.ba bezuslovno na `is_vp=1` za SVAKOG
      posjetioca — pogrešno. Korisnik je razjasnio stvaran model: `is_mp=1`
      je javni/bazni katalog koji vide SVI (i dstore.ba i digitalis.ba kad
      NIJE ulogovan) — isto kao maloprodaja. `is_vp=1` NIJE ono što
      digitalis.ba obično pokazuje; to su DODATNI artikli koje vidi SAMO
      ulogovani veleprodajni kupac (43 takva artikla, `vp=1,mp=0` — npr.
      korisnikov nalog na digitalis.ba to demonstrira uživo). Ovo je,
      dakle, ISTA stvar kao "login-gated pristup" gore, ne odvojena
      funkcija — spojeno u jednu implementaciju.
- [x] **Implementirano 2026-08-25 — filter.** Novi config ključ
      `catalog_visibility_column` je BAZNI filter, uvijek aktivan: `is_mp`
      u sve četiri konfiguracije (`config.local.php` Digitalis,
      `config.local.dstore.php` D-Store, `config.local.zed.php`,
      `config.local.optibox.php`) — svi po difoltu pokazuju isti javni
      katalog. Drugi novi ključ, `catalog_wholesale_column` (`is_vp`, u sve
      tri veleprodajne konfiguracije — Digitalis, zed.hr, optibox.rs, NE
      dstore.ba), se PROŠIRUJE preko baznog seta
      (`AND (p.is_mp = 1 OR p.is_vp = 1)`, nikad ne sužava) kad
      `ProductSearch::search()` dobije `options.wholesale_verified = true`.
      `wholesale_verified` je HARDKODOVANO `false` svuda u kodu trenutno —
      nema ga odakle pouzdano dobiti dok ne stigne login-aware API iznad
      (nikad se ne smije vjerovati flagu koji šalje sam klijent/browser).
      Kad API stigne, samo treba `ChatService`/`endpoint/chat.php` da postavi
      `wholesale_verified` na osnovu PROVJERENOG (server-side) login statusa
      i sve već radi, na sva tri veleprodajna sajta odjednom (isti kod, isti
      config ključ).
      Provjereno end-to-end: artikal 15957 (Fen za kosu 2000W, `vp=0,mp=1`)
      SE pojavljuje i na digitalis.ba (bez logina) i na dstore.ba; artikal
      6808 (Baterija litijumska CR14250, `vp=1,mp=0`) se NE pojavljuje
      nigdje dok `wholesale_verified` nije `true`, a onda se pojavljuje na
      digitalis.ba (direktan test na `ProductSearch::search()`); SQL koji
      `buildFilters()` generiše provjeren i direktno (reflection) —
      ` AND p.is_mp = 1` bez logina, ` AND (p.is_mp = 1 OR p.is_vp = 1)` sa
      `wholesale_verified`.
- [x] **dstore.ba (maloprodaja) sada zaštićen.** Bazni filter je bezuslovan
      (ne zavisi od login statusa ni od `in_stock_only`), i dstore.ba nema
      `catalog_wholesale_column` uopšte — artikal sa `is_mp=0` se NIKAD ne
      vraća iz dstore.ba pretrage, dijeljena baza ili ne.
- [x] **zed.hr/optibox.rs — potvrđeno i implementirano 2026-08-25.** Iako
      imaju odvojene kataloge/API-je (`zed.hr/api`, `optibox.rs/api`, svaki
      svoja MySQL baza `zed_chat`/`optibox_chat`), oba feeda VEĆ vraćaju
      `is_vp`/`is_mp` isto kao Digitalis — provjereno direktno (ista
      platforma/vendor). Stvarna distribucija: zed.hr 3376 oba / 21
      veleprodaja-samo / 2 maloprodaja-samo (od 3399); optibox.rs 1270 oba /
      53 veleprodaja-samo / 3 maloprodaja-samo (od 1326). Ista dva config
      ključa dodana u `config.local.zed.php`/`config.local.optibox.php`,
      oba kataloga ponovo sinhronizovana da pokupe stvarne vrijednosti.

### 🟡 Dodaj u korpu — čeka implementaciju na strani svakog sajta

Promijenjeno 2026-08-27: raniji pristup (direktan poziv pretpostavljene
`window.webshop.cart_add(id, qty, null)` funkcije) je bio nagađanje, nikad
potvrđeno protiv pravog koda nijednog sajta. Zamijenjeno event-based
pristupom — `public/embed.js`, `addToCart()` sad emituje jedan cancelable
CustomEvent na `window`:

```js
window.addEventListener('dstorechat:addtocart', function (e) {
    var p   = e.detail.product;   // {id, name, model, ean, price, url}
    var qty = e.detail.qty;       // uvijek 1 danas

    // ... ovdje ide njihova stvarna cart logika, kakva god da jeste ...

    e.preventDefault();           // OBAVEZNO - javlja widgetu da je artikal dodat
});
```

Ako niko ne pozove `preventDefault()` (nema registrovanog listenera, ili
listener postoji ali ne handluje), widget i dalje pada nazad na otvaranje
stranice artikla u novom tabu — isti siguran fallback kao prije. Ovo se
MOŽE testirati i lokalno (`demo.html`) dodavanjem privremenog
`window.addEventListener(...)` u konzoli, za razliku od starog pristupa.

- [ ] Za svaki sajt (digitalis.ba, dstore.ba, zed.hr, optibox.rs), njihov
      developer treba dodati `window.addEventListener('dstorechat:addtocart', ...)`
      koji stvarno doda artikal u NJIHOVU korpu (pozivom njihove funkcije,
      AJAX pozivom, ili kako god njihova platforma to radi), pa pozove
      `event.preventDefault()`.

### 🟢 Poznato i riješeno — samo za evidenciju

- zed.hr i optibox.rs API tokeni potvrđeni (rade, tačan broj proizvoda).
- Valute potvrđene: EUR (zed.hr), RSD (optibox.rs), KM (digitalis/dstore) —
  widget sad čita valutu sa servera, ne samo sa `data-currency` atributa.
- Nema kupovine na rate/PIO-MIO na zed.hr/optibox.rs — potvrđeno od
  korisnika, `pension_financing_available` i `installment_url` isključeni
  za ta dva sajta u configu.
- Kontakt podaci (telefon/email) za zed.hr i optibox.rs pokupljeni sa
  njihovih sajtova, upisani u `config.local.zed.php` / `config.local.optibox.php`.
- **dstore.ba dobio svoj vlastiti config i lokalni test server — 2026-08-24.**
  `dstore.ba` i `digitalis.ba` su DVA RAZLIČITA sajta/brenda (ne isto), iako
  dijele isti katalog/bazu. Napravljen `config.local.dstore.php` (isti obrazac
  kao zed/optibox) sa `shop_base_url` na dstore.ba i `shop_url_style => 'flat'`
  (dstore.ba koristi `{shop_base_url}/{id}/{seo}`, ne
  `.../webshop/proizvod/{id}/{seo}` kao digitalis.ba). Lokalni test server na
  portu 8003 (`CHATBOT_CONFIG=config.local.dstore.php php -S 127.0.0.1:8003`),
  test stranica `public/demo-dstore.html`. `config.local.php` (Digitalis) je
  ispravljen — ranije je imao `store_name => 'D-Store'` iako je zapravo
  digitalis.ba deployment; sad ispravno kaže `'Digitalis'`/`'Digitalis AI'`.
  Provjereno uživo: identity poruka i product URL stil se sad ispravno
  razlikuju između dva sajta.
- **Pravi logotipi po sajtu — 2026-08-24.** Korisnik poslao SVG za sva 4 sajta
  (`public/logo-digitalis.svg`, `logo-dstore.svg`, `logo-zed.svg`,
  `logo-optibox.svg`). Dodat `data-logo` atribut u `embed.js`/`widget.js` —
  avatar pored bot poruka sad koristi pravi logo (URL slike) umjesto ugrađenog
  D-Store wordmarka kad je postavljen. Sve 4 test stranice (`demo.html`,
  `demo-dstore.html`, `demo-zed.html`, `demo-optibox.html`) sad koriste svoj
  odgovarajući logo — provjereno uživo da se učitava (ne slomljena ikona).

### ⚪ Prije stvarnog deploya na dstore.ba/zed.hr/optibox.rs

- [ ] Prava produkcijska MySQL baza za zed.hr/optibox.rs (trenutno sync ide u
      lokalne test baze `zed_chat` / `optibox_chat` na ovoj mašini). dstore.ba
      ne treba novu bazu — dijeli `dstore_chat` sa digitalis.ba.
- [ ] Ukloniti `http://127.0.0.1:800X` iz `allowed_origins` u
      `config.local.dstore.php` / `config.local.zed.php` /
      `config.local.optibox.php` (samo za lokalni test).
- [ ] Ukloniti `rate_limit_max => 200` iz istih fajlova (samo za lokalni
      test — realan produkcijski limit treba biti mnogo niži).
- [x] **FAQ sadržaj po sajtu — riješeno 2026-08-27.** zed.hr i optibox.rs
      sad imaju svoj `prompts/faq.zed.txt`/`prompts/faq.optibox.txt`
      (pročitano direktno sa njihovih Infocentar stranica — dostava,
      plaćanje, povrat, garancija, radno vrijeme), učitava se preko novog
      config ključa `faq_file`. dstore.ba ispravno i dalje dijeli
      `prompts/faq.txt` sa Digitalisom — ista firma, isti podaci.
- [ ] Zaseban `search_api_token` po sajtu (trenutno svi dijele isti token
      kao placeholder), osim ako je namjerno da isti Make.com scenario
      opslužuje sve sajtove.

### 🟢 Pravi AI — uključen na sva 4 sajta, 2026-08-26/27

Ranije je ovdje pisalo da su svi sajtovi u mock modu — više nije tačno.
`openai_model => 'gpt-5.6-luna'`, `openai_temperature => 0.4`,
`use_mock_ai => false` je sad postavljeno i uživo testirano u sve četiri
konfiguracije (`config.local.php` Digitalis, `config.local.dstore.php`,
`config.local.zed.php`, `config.local.optibox.php`) — isti OpenAI ključ na
sva 4 (jedan account, nije vezan za sajt). Ovo je i dalje na LOKALNIM test
serverima (127.0.0.1:8000-8003) — kad stigne pravi server, samo prekopirati
isti config, ništa dodatno da se podešava oko AI-ja.
