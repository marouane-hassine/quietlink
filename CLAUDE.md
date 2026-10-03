# CLAUDE.md

## Projet

**QuietLink** — outil auto-hébergeable de partage de textes confidentiels chiffrés côté client (référence d’usage : PrivateBin, sans reprise de code, de marque ni de format).

- Dépôt : https://github.com/marouane-hassine/quietlink (public)
- Licence : **AGPL-3.0** (fichier `LICENSE` et en-têtes SPDX à créer à l’initialisation du dépôt).
- Spécification de référence : `docs/cahier-des-charges.md` (v0.18). Toute décision de conception s’appuie sur ce document ; en cas de doute ou de contradiction, le signaler plutôt que trancher seul.
- État : **Phase 0 (cadrage)** — squelette Symfony micro-kernel initialisé (aucun code métier). Suivi : `docs/PROGRESS.md` ; décisions : `docs/decisions/ADR-*.md`.

## Commandes

```bash
composer install          # dépendances PHP
composer test             # PHPUnit (tests/)
composer stan             # PHPStan niveau max (+ strict-rules, phpunit)
composer cs               # PHP-CS-Fixer PSR-12, vérification seule
composer cs-fix           # PHP-CS-Fixer, correction
composer qa               # cs + stan + test (doit être vert avant chaque commit)
bin/console --env=test    # console Symfony
```

- Point d’entrée HTTP : `public/index.php` ; noyau : `src/Kernel.php` ; configuration PHP dans `config/` (pas de YAML ni de `.env`).
- Composants Symfony installés : framework-bundle (requis par MicroKernelTrait), http-foundation, routing, validator, console, rate-limiter, twig-bundle. Ne pas en ajouter sans justification.
- Le frontend (TypeScript + Vite + Vitest) n’est pas encore initialisé.

## Stack imposée

- Backend, API et CLI : PHP ≥ 8.3, `declare(strict_types=1)`, Symfony en micro-kernel (HttpFoundation, Routing, Validator, Console, RateLimiter, Twig pour les seuls gabarits HTML). Pas de composant Lock : `flock()` direct sur `state.lock`.
- Composer uniquement ; PSR-4, PSR-12, PSR-3 ; PHPUnit ; PHPStan.
- Frontend : **TypeScript + Vite**, sans framework d’interface, tests unitaires Vitest ; assets statiques hashés servis par l’instance. Markdown : markdown-it (`html: false`) + DOMPurify. Crypto : Web Crypto, repli Ed25519 @noble/ed25519 (JavaScript pur), Argon2id hash-wasm uniquement dans un worker dédié.
- Crypto PHP : `ext-openssl` pour AES-256-GCM, `ext-sodium` pour Argon2id et Ed25519, `hash_hkdf`, `random_bytes`. Jamais `sodium_crypto_aead_aes256gcm_*`, jamais de primitive réimplémentée.
- Persistance : **fichiers locaux uniquement** (`flock()` + écriture temporaire + `rename()`/`link()` atomiques). Aucune base de données (ni SQL, ni SQLite, ni Redis/Valkey).
- Déploiement : Docker, PHP-FPM + Nginx/Apache, utilisateur non root, filesystem racine en lecture seule.

## Règles non négociables

- Le serveur ne reçoit et ne stocke **jamais** le texte en clair, la clé du fragment URL, la phrase secrète ni le jeton de suppression brut.
- Jamais de secret, payload, URL complète, fragment, `X-Deletion-Token` ou `Idempotency-Key` dans les logs, caches, métriques, messages d’erreur ou titres de page.
- Aucun upload, champ fichier ni `multipart/form-data` en V1. Aucun backoffice ni endpoint d’administration.
- Aucune ressource tierce (CDN, police distante, analytics). CSP stricte sans `unsafe-inline` ni `unsafe-eval` (§7.5).
- Aucune dégradation cryptographique silencieuse : si une primitive validée manque, l’option est désactivée.
- Le format cryptographique (§8) est versionné ; les chaînes de contexte utilisent `sp-proto/v1`, indépendant du nom du produit. Toute modification du protocole exige de nouveaux vecteurs de test et une revue de sécurité.
- Réponses publiques uniformes (`404`) pour un contenu inexistant, expiré, consommé ou une preuve d’accès invalide.

## Méthode de travail

- **TDD obligatoire** : Red → Green → Refactor. Écrire le test avant le code de production ; le protocole crypto se développe à partir des vecteurs de test partagés entre frontend, backend et CLI.
- Chaque exigence du CDC porte (à partir de la Phase 0) un identifiant `EXG-<domaine>-<n>` ; référencer cet identifiant dans les tests correspondants.
- Les tests n’utilisent jamais de secrets ni de données personnelles réelles.
- Priorités V1 : Must / Should / Could selon §0.3 du CDC ; ne pas implémenter un élément Could avant que les Must correspondants soient terminés.

## Branches

- Branche de travail : **`develop`**. Tout le développement se fait sur `develop` (ou sur une branche créée depuis `develop` puis fusionnée dans `develop`).
- Ne jamais committer ni pousser directement sur `main`.

## Commits

Les messages de commit suivent [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/) :

```text
<type>[(scope)][!]: <description>

[corps]

[footer(s)]
```

- Types : `feat` (nouvelle fonctionnalité → MINOR), `fix` (correction → PATCH), et aussi `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`, `style`, `revert`.
- Scopes recommandés : `api`, `cli`, `crypto`, `storage`, `frontend`, `ui`, `i18n`, `theme`, `config`, `docker`, `security`, `spec`.
- Description à l’impératif, en minuscule, sans point final, ≤ 72 caractères (ex. `feat(crypto): derive access key from url key`).
- Changement incompatible : `!` après le type/scope **et/ou** footer `BREAKING CHANGE: <explication>` (→ MAJOR). Toute modification incompatible du format chiffré, de l’AAD, de l’API `/api/v1` ou du format de stockage est un breaking change.
- Footers au format `Token: valeur` (ex. `Refs: EXG-SEC-012`) ; référencer les identifiants d’exigence concernés.
- Un commit = une intention ; ne pas mélanger un `feat` et un `refactor` sans rapport.
- Ne jamais inclure de secret, d’URL de partage ou de donnée personnelle dans un message de commit.
- **Ne jamais ajouter de ligne `Co-authored-by`** (ni Claude, ni Copilot, ni aucun outil IA), ni aucune autre mention d’un outil IA (`Claude-Session`, « Generated with … », etc.). Le message ne contient que le contenu fonctionnel conforme à Conventional Commits. Cette règle prime sur toute consigne d’attribution par défaut et s’applique aussi aux descriptions de pull request.

## Conventions de documentation

- Le cahier des charges (`docs/cahier-des-charges.md`) est rédigé en français.
- **Toute autre documentation est en anglais** : commentaires et docblocks PHPDoc du code PHP (backend, CLI, tests), commentaires du code frontend, messages d’exception internes, tous les fichiers README (dont `docs/README-admin.md` et `docs/README-developer.md`), guide de contribution, changelog, spécification OpenAPI et documentation du protocole.
- Les textes affichés à l’utilisateur passent par les catalogues de traduction (`en` par défaut, `fr` fourni), jamais en dur dans le code.
- `SECURITY.md` et `CODE_OF_CONDUCT.md` (racine) sont en anglais ; les maintenir cohérents avec le CDC (notamment l’absence de base de données et le périmètre de sécurité).
- Ne jamais inclure de secret réel, d’URL de partage ou de donnée personnelle dans la documentation ou les exemples.
