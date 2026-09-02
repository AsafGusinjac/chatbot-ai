# Za developera — šta treba da uradiš

Ovo je kratak spisak SAMO stvari koje treba TI da uradiš na strani sajta
(digitalis.ba / dstore.ba / zed.hr / optibox.rs). Sve ostalo (backend, AI,
pretraga, config) je već gotovo i testirano na našoj strani.

Za tehničku pozadinu/istorijat odluka vidi `docs/deployment.md` — taj fajl
nije potreban da bi se ovo ispod uradilo.

## 1. Ubaci widget na stranicu

Jedna linija, prije `</body>`:

```html
<script src="https://TVOJ-DOMEN/chatbot/public/embed.js"
        data-endpoint="https://TVOJ-DOMEN/chatbot/endpoint/chat.php" defer></script>
```

Widget se crta unutar Shadow DOM-a — ne dira CSS stranice, CSS stranice ne
dira njega. Bez jQuery, bez dodatnih fajlova.

Ako isti backend hostuje više brendova/sajtova, boja, logo i naslov mogu se
podesiti direktno na script tag-u:

```html
<script src="https://TVOJ-DOMEN/chatbot/public/embed.js"
        data-endpoint="https://TVOJ-DOMEN/chatbot/endpoint/chat.php"
        data-title="Digitalis AI asistent"
        data-color="#c43a00"
        data-webshop="digitalis"
        data-logo="https://TVOJ-SAJT/path/logo.svg"
        defer></script>
```

`data-webshop` treba postaviti po sajtu da se feedback/ocjene u bazi mogu
razdvojiti:

- `digitalis`
- `dstore`
- `zed`
- `optibox`

Za **dstore.ba** dodati i kontakt preset, jer Dstore uklanja postojeći
floating chat za Messenger/WhatsApp/Viber i te opcije preuzima AI widget:

```html
<script src="https://TVOJ-DOMEN/chatbot/public/embed.js"
        data-endpoint="https://TVOJ-DOMEN/chatbot/endpoint/chat.php"
        data-title="Dstore AI asistent"
        data-webshop="dstore"
        data-contact-preset="dstore"
        defer></script>
```

`data-webshop="dstore"` govori backendu da product-card dugme "Detalji" treba
voditi na `https://www.dstore.ba/{id}/{seo}`, a ne na Digitalis product URL.

`data-contact-preset="dstore"` automatski prikazuje:

- WhatsApp: `https://wa.me/38761094094`
- Viber: `viber://contact?number=%2B38761095095`
- Messenger: `https://m.me/1443840129227893`

Za druge sajtove ne stavljati `data-contact-preset="dstore"` osim ako stvarno
žele iste Dstore kontakt linkove.

## 2. Pošalji kontekst otvorenog artikla

Na product/detail stranici pošalji ID, naziv i URL artikla koji kupac trenutno
gleda. Tada chat, čim ga kupac otvori, može ponuditi pitanja tipa stanje,
cijena, garancija za baš taj artikal.

Najjednostavnije je dodati product podatke direktno na isti widget script:

```html
<script
  src="https://falcom.ba/chatbot/public/embed.js"
  data-endpoint="https://falcom.ba/chatbot/endpoint/chat.php"
  data-title="Digitalis AI asistent"
  data-webshop="digitalis"
  data-product-id="62577"
  data-product-name="Naziv otvorenog artikla"
  data-product-url="https://www.digitalis.ba/webshop/proizvod/62577/slug"
  defer>
</script>
```

Za Dstore isto, samo `data-webshop="dstore"` i Dstore URL:

```html
<script
  src="https://falcom.ba/chatbot/public/embed.js"
  data-endpoint="https://falcom.ba/chatbot/endpoint/chat.php"
  data-title="Dstore AI asistent"
  data-webshop="dstore"
  data-contact-preset="dstore"
  data-product-id="62577"
  data-product-name="Naziv otvorenog artikla"
  data-product-url="https://www.dstore.ba/62577/slug"
  defer>
</script>
```

Ako product podatke ne može staviti direktno na script tag, može ih poslati
kroz `DstoreChat('product', ...)`:

```html
<script>
  window.DstoreChat = window.DstoreChat || function () {
    (window.DstoreChat.q = window.DstoreChat.q || []).push(arguments);
  };
</script>
<script src="https://TVOJ-DOMEN/chatbot/public/embed.js"
        data-endpoint="https://TVOJ-DOMEN/chatbot/endpoint/chat.php" defer></script>
<script>
  DstoreChat('product', {
    id: 62577,
    name: 'Naziv otvorenog artikla',
    url: window.location.href
  });
</script>
```

Može se pozvati prije ili poslije učitavanja widgeta, i prije ili poslije
otvaranja chat prozora. Ako se artikal mijenja kroz AJAX, pozvati ponovo sa
novim podacima. Ako kupac nije na product/detail stranici, ovaj poziv se ne
šalje.

Ako je developeru lakše kroz browser event, može i ovako:

```js
window.dispatchEvent(new CustomEvent('dstorechat:product', {
    detail: {
        id: 62577,
        name: 'Naziv otvorenog artikla',
        url: window.location.href
    }
}));
```

Za provjeru šta je widget stvarno pokupio, otvoriti Console i pokrenuti:

```js
DstoreChat('debug')
```

Treba da vrati npr.:

```js
{
  webshop: "dstore",
  product: { id: "62577", name: "Naziv otvorenog artikla", url: "..." },
  visitor: { customerId: "", customerName: "", isWholesaleCustomer: false }
}
```

## 3. Dodaj u korpu — event listener

Kad kupac klikne "Dodaj u korpu" u chatu, widget emituje event na
`window`. Tvoj kod ga sluša i sam odlučuje kako da doda artikal u korpu
(pozivom svoje funkcije, AJAX pozivom, kako god vaša platforma radi):

```js
window.addEventListener('dstorechat:addtocart', function (e) {
    var p   = e.detail.product;   // {id, name, model, ean, price, url}
    var qty = e.detail.qty;       // uvijek 1 danas

    // ... ovdje ide vaša stvarna cart logika ...

    e.preventDefault();           // OBAVEZNO — javlja widgetu da je artikal dodat
});
```

Brzi test bez stvarne korpe:

```js
window.addEventListener('dstorechat:addtocart', function (e) {
    console.log('AI add to cart event:', e.detail);
    e.preventDefault();
});
```

Nakon toga klik na "Dodaj u korpu" u chatu treba u Console ispisati product
podatke. Tek onda taj `console.log` zamijeniti stvarnim AJAX/funkcijom za korpu.

Ako ovaj listener ne dodaš (ili ga dodaš ali ne pozoveš `preventDefault()`),
widget i dalje radi — samo otvara stranicu artikla u novom tabu umjesto
direktnog dodavanja u korpu. Nije blokirajuće, ali je bolje iskustvo ako se
implementira.

## 4. Prijava ulogovanog veleprodajnog kupca (samo digitalis.ba, zed.hr, optibox.rs)

*(dstore.ba nema login/veleprodaju — ovo se NE odnosi na dstore.ba.)*

Ulogovani veleprodajni kupci treba da vide dodatne artikle i stanje na
lageru koje maloprodaja ne vidi. To backend NE SMIJE pokazati dok mu vaš
sajt eksplicitno ne kaže da je kupac stvarno ulogovan — nikad na osnovu
nečega što bi sam browser tvrdio.

Kad znate da je kupac ulogovan (na strani vašeg sajta, poslije provjere
sesije/logina), pozovite:

```html
<script>
  window.DstoreChat = window.DstoreChat || function () {
    (window.DstoreChat.q = window.DstoreChat.q || []).push(arguments);
  };
</script>
<script src="https://TVOJ-DOMEN/chatbot/public/embed.js" data-endpoint="..." defer></script>
<script>
  DstoreChat('identify', {
    customerId: '12345',           // vaš interni ID kupca
    customerName: 'Ivan Ivić',     // opciono — koristi se za personalizaciju
    isWholesaleCustomer: true,     // false ili izostavi ako NIJE ulogovan/nije veleprodaja
    color: '#0064b4',              // opciono — može promijeniti boju widgeta
    logo: 'https://.../logo.svg'   // opciono — može promijeniti logo/avatar
  });
</script>
```

Ako se koristi event umjesto command poziva:

```js
window.dispatchEvent(new CustomEvent('dstorechat:identify', {
    detail: {
        customerId: '12345',
        customerName: 'Ivan Ivić',
        isWholesaleCustomer: true
    }
}));
```

Poslije toga `DstoreChat('debug')` treba u `visitor` dijelu pokazati tog kupca.

Radi bez obzira da li se pozove prije ili poslije `embed.js` tag-a (isti
obrazac kao Google Analytics/Intercom). Može se pozvati više puta — npr.
ponovo sa `isWholesaleCustomer: false` kad se kupac izloguje.

**Bez ovog poziva ulogovani veleprodajni kupci vide isti katalog kao
neulogovani posjetioci** — nema drugog načina da backend sazna da je neko
ulogovan.

## 5. Brzi test nakon ubacivanja

Na realnoj stranici provjeriti:

- ikonica chata se vidi dole na desktopu i mobitelu
- na desktopu se nakon oko 10 sekundi pojavi mali pozdrav pored ikonice
- poruka radi bez `Unauthorized` greške
- proizvod kartice imaju slike
- brand izbor/slider se vidi kad bot pita za brend
- klik na brand izbor šalje novu poruku
- "Dodaj u korpu" stvarno ubaci artikal u korpu, ne samo da otvori product page
- na product/detail stranici chat prepozna trenutno otvoren artikal
- ulogovan veleprodajni kupac šalje `identify()` prije prve poruke ili odmah nakon logina

## 6. Šta treba od servera/hostinga (kad budete spremni za pravi deploy)

- PHP 7.4 ili noviji
- MySQL/MariaDB baza
- Nalog baze ograničen samo na tu bazu
- Mogućnost da se pokrene cron/Task Scheduler jednom noću (sinhronizacija kataloga)
- HTTPS na endpoint-u (`/chatbot/endpoint/chat.php`)
- Privatan writable folder za `data/ratelimit`

Javite kad je server spreman (SSH/FTP pristup, domen, pristup MySQL bazi) —
mi ćemo popuniti produkcioni config i pokrenuti prvi sync.
