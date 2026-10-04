# Cahier des charges — QuietLink, outil de partage de textes confidentiels

**Statut :** version 0.22 — projet de cahier des charges produit et technique  
**Périmètre :** V1  
**Technologie obligatoire :** PHP avec Symfony pour le backend, l’API et la CLI ; TypeScript avec Vite pour le frontend  
**Licence :** GNU Affero General Public License v3.0 (AGPL-3.0)  
**Référence fonctionnelle :** PrivateBin (projet libre sous licence zlib), utilisé comme référence d’usage uniquement : aucune reprise de marque, de logo, d’identité visuelle ni de code, et aucune compatibilité de format visée  
**Date :** 4 octobre 2026

**Historique :**

- 0.22 — outillage d’exploitation (§15.1) : `app:boot --dry-run`, sorties JSON et codes de sortie documentés de `app:boot` et `app:config:check`, `app:secret:generate --output`, événements d’exploitation journalisés sans identifiant, validation locale et test de fumée en Docker ; marqueurs de création en cours dans `state/creating/` ; correction : seule la purge retire les répertoires `<id>/` incomplets (et non `app:boot`).
- 0.21 — logo QuietLink : symbole bouclier et maillons, mot-symbole, versions claire et sombre en SVG, intégration en ligne colorée par les tokens et favicon.
- 0.20 — langues : l’application doit accepter les langues écrites de droite à gauche (RTL) en plus des langues LTR ; langues fournies en V1 : anglais (référence et fallback), français, espagnol, italien et arabe (RTL) ; catalogues découverts automatiquement et chargés à la demande ; contenu utilisateur avec direction automatique, liens et code toujours LTR.
- 0.19 — répertoire de données configurable par `storage.data_dir`, par défaut `datas/` à la racine du projet (hors de `public/`) ; les répertoires de contenus, d’idempotence, de rate limiting et d’état en dérivent par défaut et restent configurables individuellement ; en Docker, le volume de données est monté sur ce répertoire.
- 0.18 — décisions de cadrage (licence AGPL-3.0, TypeScript + Vite, markdown-it + DOMPurify, @noble/ed25519 + hash-wasm, Argon2id 64 Mio / t = 3, listes de mots EFF et Lexique, Sigstore, audit externe ciblé, palette validée) ; retrait des honeypots ; identifiant de 192 bits liant aussi le jeton de suppression, pour une pré-vérification de `DELETE` sans stockage ; idempotence : contrôle d’empreinte en cas de course, purge des contenus orphelins, rate limiting des rejeux, détails de `link()` ; consommation idempotente après `consumed` ; quotas sous verrou.
- 0.17 — idempotence sans état intermédiaire (enregistrement écrit une seule fois, après création, par `link()` atomique) : aucune reprise ne réutilise un identifiant ; identifiant retiré du corps de création ; pré-vérification sans stockage de `consume` ; limites de l’AAD documentées ; définition de `tronc64` ; contrôles client de l’identifiant et de l’AAD.
- 0.16 — identifiant composé d’une empreinte de `access_pk` (64 bits) et d’un aléa attribué par le serveur (64 bits) : un détenteur du lien ne peut plus jamais recréer de contenu sous un lien existant ; retrait de `created_at` de l’AAD, suppression de la fenêtre de création et des pierres tombales autres que `consumed` ; identifiant de réservation généré par le client ; idempotence consultée avant quotas et rate limiting ; proxies de confiance et adresses IPv4 mappées ; relecture d’état après verrou étendue aux états terminaux avec contrôle d’inode ; nettoyage des `pending` en échec ; empreinte du secret dans le marqueur d’amorçage ; `ExecReload` ; purge conditionnée à l’amorçage ; `health.json` périmé bloquant ; limite documentée de `DELETE`.
- 0.15 — date de création authentifiée dans l’AAD et fenêtre de création (fin de la recréation d’un contenu supprimé ou consommé), pierres tombales, vérification de `access_pk` avant tout accès au stockage, nouvelle tentative sur tout `404`, Ed25519 de repli en JavaScript pur, Argon2id limité au worker, `allow_passphrase` limité à la création, transmission du secret d’instance à PHP-FPM, codes d’expiration fermés, agrégation IPv6, répertoire d’état, volume distinct pour les assets générés, planification de la purge en conteneur, systemd, règles de validation et ajustements UX et accessibilité.
- 0.14 — identifiant dérivé de `access_pk` (fin de l’oracle d’existence), challenge `consume` stocké en clair et vérifié sans HMAC, consommation idempotente, rate limiting par identifiant limité aux preuves valides, compteurs de stockage persistants et contrôle des inodes hors requête, commande d’amorçage `app:boot` et configuration lue à l’exécution, verrous `flock()` sans `FlockStore`, idempotence avec création exclusive et reprise, codes HTTP, base64url canonique, surrogates isolés, resynchronisation après veille, tokens de bordure et de focus conformes, CLI sur `/dev/tty`, purge sous verrou global, aucune image dans le Markdown, conteneur non-root obligatoire, bornes de `max_unconfirmed_opens`.
- 0.13 — ouverture de `state.lock` sans création, contenu composé de quatre fichiers, suppression immédiate du payload à la consommation, liste précise des secrets effacés sur l’écran de résultat, preuve d’accès en Phase 1, message de navigateur incompatible pour la phrase secrète, rotation du secret d’instance, barre de lecture mobile, message de partage conditionnel, limite du module Ed25519 JavaScript.
- 0.12 — module Ed25519 local obligatoire en repli, état `deleted` et relecture d’état après verrou, renouvellement des challenges expirés, challenge de consommation lié à la réservation, `allow_read_once` ; UX : taille dans la ligne de réglages, confirmation avant perte du lien de gestion, message d’échec d’envoi, masquage d’urgence, double saisie de la phrase secrète masquée, barre d’action contextuelle, règles de focus, seuil de coloration syntaxique, tests de zoom et de gros contenus.
- 0.11 — vérification locale de la phrase secrète avant réservation, reprise de réservation après rechargement, format des challenges et messages signés, limites de taille portant sur l’enveloppe sérialisée, cohérence `allow_forever`/`max_retention`, séparation payload/état dans le stockage, corrections CSP et Web Workers, `ext-intl`, répertoire d’assets générés, page de gestion ; améliorations UX : réglages en une ligne et préréglages, écran « Révéler » de la lecture unique, copie champ par champ des templates, phrase secrète en mots, détection de lien tronqué, désactivation des correcteurs, tests utilisateurs.
- 0.10 — refonte du protocole de lecture (preuve d’accès avant remise du payload, détection des ouvertures non confirmées), séparation des clés, AAD canonique RFC 8785, encodages et limites de taille, quotas de stockage, rétention de l’idempotence, CLI et phrase secrète, CSP des workers, conventions d’exigences et priorisation V1.
- 0.9 — version initiale.

## 0. Conventions

### 0.1 Niveaux d’exigence

Les termes sont interprétés selon la RFC 2119 :

- « doit », « obligatoire », « interdit », « ne doit jamais » = **MUST / MUST NOT** ;
- « devrait », « recommandé » = **SHOULD** ;
- « peut », « optionnel » = **MAY**.

Les formulations « lorsque possible », « autant que possible » ou « si la plateforme le permet » signifient : l’exigence s’applique par défaut, et toute impossibilité doit être justifiée, documentée et validée lors de la revue de sécurité. Un écart non documenté est une non-conformité.

### 0.2 Traçabilité

En Phase 0, chaque exigence de ce document reçoit un identifiant stable (`EXG-<domaine>-<numéro>`, par exemple `EXG-SEC-012`, `EXG-UX-034`, `EXG-CRYPTO-005`) dans une matrice de traçabilité versionnée avec le code. Cette matrice relie chaque exigence à au moins un test d’acceptation (§16.0) et sert de référence aux critères de livraison (§16.4).

### 0.3 Priorisation V1

| Priorité | Contenu | Effet sur la livraison |
|---|---|---|
| **Must** | chiffrement local, protocole de lecture et lecture unique, expiration, suppression, API, CLI, stockage de fichiers, sécurité HTTP, i18n `en`/`fr`, accessibilité des parcours principaux, Docker, documentation | bloquant |
| **Should** | Markdown et coloration syntaxique, templates Markdown (saisie), copie champ par champ et masquage des champs sensibles des templates à la lecture, préréglages de création, QR code, thèmes clair/sombre, tokens personnalisés | bloquant sauf dérogation écrite |
| **Could** | rendu structuré enrichi des templates à la lecture, QR code Wi-Fi, détection locale de secrets, aperçu de thème, export local, impression, manifest | non bloquant, reportable en V1.1 |

### 0.4 Identifiant de protocole

Les chaînes de contexte cryptographiques utilisent l’identifiant neutre `sp-proto/v1`, figé en Phase 0 et indépendant du nom commercial du produit. Le produit s’appelle **QuietLink** ; la CLI se nomme `quietlink` et les chemins système utilisent `quietlink`. Un changement ultérieur du nom commercial n’affecte pas le protocole.

## 1. Vision

Créer un outil de partage de textes et de snippets confidentiels, rapide, simple et moderne, dont l’API et le stockage ne reçoivent et ne conservent jamais le contenu en clair.

La confidentialité dépend toutefois de l’intégrité du frontend distribué : une instance compromise capable de modifier le JavaScript peut tenter de récupérer une clé au moment du déchiffrement.

L’utilisateur doit pouvoir :

1. rédiger ou coller un texte ;
2. le chiffrer localement dans son navigateur ;
3. obtenir un lien partageable ;
4. permettre au destinataire de consulter le contenu sans créer de compte ;
5. définir une durée de vie ou une lecture unique ;
6. supprimer le contenu lorsque cela est possible.

Le produit doit privilégier la confidentialité, la simplicité et la fiabilité plutôt que l’accumulation de fonctionnalités.

## 2. Objectifs de la V1

- Fournir une alternative moderne et crédible à PrivateBin.
- Ne jamais transmettre le texte en clair au serveur.
- Proposer une expérience excellente sur mobile et desktop.
- Permettre un déploiement simple avec Docker.
- Ne nécessiter aucun moteur de base de données ; utiliser uniquement le stockage de fichiers local sécurisé.
- Limiter les métadonnées conservées au strict nécessaire.
- Rendre le comportement de sécurité documenté, testable et auditable.
- Fournir une API minimale et une CLI pour les usages développeurs.
- Construire le serveur, l’API et la CLI en PHP.

## 3. Hors périmètre V1

Les éléments suivants ne doivent pas être développés en V1 :

- partage de fichiers, pièces jointes, upload ou transfert de fichiers ;
- SSO, OAuth, LDAP ou intégration d’annuaire ;
- quotas par utilisateur ou par organisation ;
- administration entreprise, multi-tenant ou facturation ;
- backoffice web, tableau de bord ou interface d’administration ;
- espaces d’équipe ;
- synchronisation de documents ;
- édition collaborative temps réel ;
- messagerie ou commentaires ;
- moteur de recherche du contenu ;
- récupération d’un secret perdu ;
- déchiffrement côté serveur ;
- publicité, tracking comportemental ou analytics individualisés.

## 4. Public cible

### 4.1 Développeurs et administrateurs système

Partage ponctuel de logs, tokens temporaires, extraits de configuration, erreurs et snippets.

### 4.2 Équipes techniques

Partage de texte sensible entre collègues via un lien court et temporaire, sans l’insérer directement dans Slack, un ticket ou un e-mail.

### 4.3 Utilisateurs soucieux de leur vie privée

Transmission de notes ou d’informations confidentielles sans compte et sans conservation longue durée.

## 5. Principes produit

- **Privacy by design :** le serveur ne reçoit que le minimum nécessaire.
- **Secure by default :** les paramètres dangereux ne doivent pas être les choix par défaut.
- **Sans compte :** créer et consulter un contenu ne nécessite aucun compte en V1.
- **Mobile first :** les flux principaux doivent être utilisables au pouce.
- **Transparence :** la menace, les limites et le fonctionnement cryptographique doivent être expliqués clairement.
- **Déploiement autonome :** une instance doit pouvoir être installée et sauvegardée par une personne compétente.
- **Administration sans backoffice :** les options d’instance sont gérées par fichier de configuration, variables d’environnement, Docker et CLI.
- **Échec sûr :** en cas d’erreur de chiffrement, le contenu ne doit pas être envoyé.
- **Interopérabilité raisonnée :** API et CLI doivent rester simples, stables et documentées.
- **Développement piloté par les tests :** toute fonctionnalité doit être spécifiée par des tests avant son implémentation.

### 5.1 Règles UX et ergonomie obligatoires

L’interface doit être conçue pour qu’un utilisateur comprenne immédiatement quoi faire, ce qui va être envoyé et ce qui est protégé.

#### Simplicité des parcours

- l’écran d’accueil doit présenter directement l’éditeur ;
- aucune inscription, connexion ou configuration ne doit être demandée pour créer un contenu ;
- une seule action principale doit être mise en avant par écran ;
- les actions secondaires doivent rester visibles mais discrètes ;
- les parcours principaux création, lecture et suppression doivent être accessibles en peu d’étapes ;
- aucun tunnel d’onboarding obligatoire ;
- aucun écran vide sans explication ni action possible ;
- aucun dark pattern, publicité ou incitation à créer un compte.

#### Éditeur

- l’éditeur doit être utilisable immédiatement après le chargement ;
- la V1 accepte uniquement du texte, du texte brut, du Markdown et des snippets de code saisis ou collés ;
- aucun fichier ne doit pouvoir être joint, téléversé ou envoyé au serveur ;
- aucun formulaire `multipart/form-data`, champ de type fichier ou endpoint d’upload ne doit être développé en V1 ;
- le glisser-déposer d’un fichier doit être bloqué et afficher un message indiquant que seuls les contenus textuels sont pris en charge ;
- le curseur doit être placé dans la zone de saisie lorsque cela ne gêne pas le clavier mobile ;
- l’éditeur et le champ de phrase secrète doivent porter `spellcheck="false"`, `autocorrect="off"`, `autocapitalize="off"` et `autocomplete="off"` : certains navigateurs transmettent le texte à un service distant de correction orthographique et les claviers mobiles mémorisent les mots saisis, ce qui ferait sortir le contenu avant chiffrement ; les navigateurs pouvant ignorer `autocomplete="off"` sur les champs de type mot de passe, le champ de phrase secrète est un champ texte masqué par CSS (`-webkit-text-security`) lorsque `CSS.supports()` confirme sa prise en charge, et un champ `type="password"` dans le cas contraire ; le champ masqué porte un `aria-describedby` indiquant qu’il est masqué, et les lecteurs d’écran ne doivent pas en vocaliser la valeur ; la limite résiduelle (proposition d’enregistrement par un gestionnaire de mots de passe) est documentée ;
- la taille de police des champs de saisie est d’au moins 16 px afin d’éviter le zoom automatique au focus sur iOS ;
- le collage de texte doit fonctionner sans perdre les retours à la ligne ;
- lorsque l’éditeur est vide, un bouton « Coller depuis le presse-papier » peut être affiché au centre de la zone de saisie ;
- la lecture via `navigator.clipboard.readText()` ne doit être déclenchée qu’après une action explicite de l’utilisateur, dans un contexte sécurisé et après l’autorisation du navigateur ;
- le presse-papier ne doit jamais être lu automatiquement au chargement, envoyé au serveur, écrit dans un stockage local ou ajouté aux logs ;
- si l’API Clipboard est indisponible ou refusée, l’interface doit conserver le collage manuel et afficher un message non bloquant ;
- le format choisi doit être visible en permanence ;
- les options avancées doivent être regroupées sans masquer l’action principale ;
- l’éditeur doit indiquer clairement si le contenu est vide ;
- les limites de taille doivent être affichées avant la publication ;
- la taille doit être calculée localement sur l’enveloppe sérialisée (§8.2.1), c’est-à-dire en octets après encodage JSON et UTF-8, sans envoyer le contenu au serveur ; la limite affichée est donc exactement celle que le serveur appliquera au ciphertext ;
- une jauge de taille discrète doit apparaître lorsque la saisie commence à approcher de la limite configurée ; elle doit être accompagnée d’une valeur textuelle et ne doit jamais être le seul indicateur ;
- la jauge doit utiliser des seuils documentés, une couleur d’attention puis une couleur de blocage, avec un libellé accessible et une annonce lorsque la limite est atteinte ;
- les brouillons en clair ne doivent pas être sauvegardés automatiquement en V1 ;
- quitter, recharger ou fermer une saisie non publiée doit afficher un avertissement lorsque le navigateur le permet ;
- cet avertissement ne doit jamais écrire le brouillon dans `localStorage`, `sessionStorage`, IndexedDB, un cookie, l’URL ou un service externe ;
- l’avertissement doit être activé uniquement lorsqu’un contenu non vide et non publié existe afin de ne pas perturber les parcours sans saisie.

#### Aides à la décision

- les réglages actifs sont affichés en permanence sur une seule ligne juste au-dessus de l’action principale, avec la taille courante, par exemple « Expire dans 1 jour · Lecture multiple · Sans phrase secrète · 12 Kio » ; cette ligne sert de résumé avant publication ; chaque élément se modifie d’une action, via un menu sur desktop et un panneau glissant (*bottom sheet*) sur mobile ;
- des préréglages nommés sont proposés en plus du réglage manuel : « Secret » (lecture unique, 1 heure) et « Partage » (7 jours) ; les durées des préréglages sont ramenées aux durées autorisées par l’instance, et le préréglage « Secret » n’est pas proposé si l’instance désactive la lecture unique ;
- avant tout chiffrement, une validation locale vérifie : contenu non vide, taille de l’enveloppe autorisée, phrase secrète confirmée lorsqu’elle est requise, capacités cryptographiques disponibles, expiration autorisée et cohérence des options ; tant qu’elle échoue, l’action principale reste désactivée avec la raison affichée, et aucune requête n’est envoyée ;
- lorsqu’un template sensible est choisi ou que le texte collé correspond localement à un motif de secret connu (clé privée PEM, jetons GitHub, AWS, Slack, JWT…), l’interface suggère le préréglage « Secret » ; cette suggestion est calculée uniquement dans le navigateur, n’est jamais appliquée silencieusement et peut être ignorée (priorité Could) ;
- le format (texte, Markdown, code) et le langage peuvent être suggérés localement à partir du contenu collé, sans modification silencieuse du choix de l’utilisateur ;
- raccourcis clavier : `Ctrl/Cmd + Entrée` déclenche « Chiffrer et créer le lien », `Échap` ferme le panneau ouvert ; les raccourcis sont documentés et accessibles ;
- un panneau « Ce qui est envoyé au serveur », consultable avant publication, liste la taille chiffrée, l’expiration, le mode lecture unique et la présence d’une phrase secrète, et rappelle que le texte et la clé ne quittent pas le navigateur ;
- un schéma « Comment ça marche » en trois étapes est accessible depuis le pied de page, sans tunnel d’onboarding.

#### Données sensibles

- le bouton principal doit s’appeler « Chiffrer et créer le lien » ou formulation équivalente ;
- aucune action ne doit envoyer le contenu avant le chiffrement ;
- la saisie d’une phrase secrète doit afficher clairement qu’elle devra être transmise au destinataire par un autre canal que le lien ;
- le champ de phrase secrète doit proposer une action « Générer une phrase secrète forte » ou formulation équivalente ;
- la génération doit utiliser un générateur aléatoire cryptographiquement sûr côté navigateur, ne doit pas utiliser de valeur prédéfinie et ne doit pas persister la phrase secrète ;
- la phrase générée est composée de mots séparés par des tirets, tirés d’une liste de mots propre à la langue active (au moins 2 048 mots, sans homophones ni mots ambigus : liste EFF « large » de 7 776 mots pour `en`, liste dérivée de la base Lexique pour `fr`, §19), avec au moins 6 mots, soit au moins 66 bits d’entropie (77 bits avec la liste EFF) ; elle doit pouvoir être dictée par téléphone ;
- un indicateur de robustesse, calculé localement et léger (longueur, classes de caractères, entropie estimée, mots les plus courants), accompagne la saisie manuelle ; il ne bloque pas la publication ;
- le module Argon2id est préchargé dès que le champ de phrase secrète reçoit le focus, afin que le chiffrement ne commence pas par un temps de chargement ;
- la phrase générée doit pouvoir être copiée explicitement, avec un retour visuel ne révélant pas sa valeur ;
- le champ de phrase secrète propose une action « Afficher / Masquer » ; une phrase saisie manuellement alors que le champ est masqué doit être confirmée par une seconde saisie ; la confirmation n’est pas demandée pour une phrase générée ni lorsque le champ est affiché ;
- aucun message d’erreur, d’état ou de validation ne contient la phrase secrète ;
- la génération doit rester disponible sans remplacer silencieusement une phrase déjà saisie ; une confirmation ou une action d’annulation doit être proposée ;
- les champs sensibles des templates doivent pouvoir être masqués et révélés ;
- la révélation d’un secret doit être une action explicite ;
- la copie d’un secret doit afficher une confirmation temporaire et ne doit pas révéler le secret dans l’interface ;
- le contenu déchiffré doit pouvoir être masqué rapidement sur mobile ;
- lors du passage de l’application en arrière-plan, l’interface doit masquer le contenu si le navigateur le permet ;
- aucun fichier local ne doit être envoyé ou synchronisé automatiquement ;
- aucun contenu en clair ne doit apparaître dans les notifications, titres de page, URLs, logs ou messages d’erreur ;
- la suppression d’un contenu doit demander une confirmation claire et expliquer qu’elle est irréversible.

#### États et retours utilisateur

Chaque action réseau ou cryptographique doit avoir un état visible :

- prêt ;
- traitement ;
- réussite ;
- échec récupérable ;
- échec définitif.

Règles :

- pendant une opération Argon2id, de chiffrement ou de déchiffrement, afficher des états textuels explicites tels que « Initialisation de la cryptographie », « Dérivation de la clé » et « Déchiffrement » ;
- les opérations coûteuses doivent être exécutées dans un Web Worker afin de préserver la réactivité de l’interface ; Argon2id s’exécute obligatoirement dans un worker dédié (§7.5) ;
- ne jamais afficher une progression chiffrée fictive ni révéler la phrase secrète, la clé ou le payload dans ces états ;
- les changements d’état doivent être annoncés de manière accessible sans interrompre inutilement la lecture avec une zone `aria-live` appropriée ;
- désactiver l’action de publication pendant le chiffrement et l’envoi ;
- empêcher les doubles clics et les doubles créations accidentelles ;
- afficher un retour après chaque copie, création, suppression ou changement de langue ;
- fournir une action de récupération lorsque l’erreur est récupérable ;
- employer un langage compréhensible, sans exposer de stack trace ;
- annoncer une durée indicative pour Argon2id, issue de la calibration (« environ 2 secondes »), sans barre de progression fictive ;
- détecter la perte de connexion (`navigator.onLine` et événements `online`/`offline`) et proposer une nouvelle tentative explicite ;
- à chaque changement d’écran (création → résultat, chargement → lecture), placer le focus sur le titre du nouvel écran ; un lien d’évitement vers le contenu principal est disponible ;
- les confirmations de succès (copie, création, changement de langue) sont annoncées par la zone `aria-live` sans déplacer le focus ; le focus n’est déplacé que lors d’un changement d’écran ou vers le champ concerné par une erreur de validation ; il n’est jamais déplacé de façon inattendue ;
- après un échec d’envoi, afficher « Le contenu n’a pas été envoyé », avec les actions « Réessayer » et « Annuler » ; « Réessayer » renvoie la même requête avec la même clé d’idempotence (§10), sans rechiffrement ; « Annuler » revient à l’éditeur, texte conservé ;
- ne jamais utiliser uniquement la couleur pour signaler un état ;
- conserver le texte localement dans l’éditeur tant que la page reste ouverte après une erreur réseau ;
- ne jamais retenter automatiquement une opération sensible sans l’indiquer.

#### Écran de résultat

Après la création, l’écran doit afficher :

- une confirmation claire du chiffrement réussi ;
- le lien de partage dans un champ facilement copiable ;
- un bouton Copier toujours visible ;
- un bouton QR code optionnel ;
- l’expiration et le mode lecture unique ;
- un avertissement indiquant que le lien complet doit être partagé avec précaution ;
- en lecture unique, un avertissement explicite et visible avant le bouton Copier : « N’ouvrez pas ce lien pour le tester : son ouverture le consommera » ou formulation équivalente ; le lien de partage ne doit pas être cliquable dans l’écran de résultat ;
- une action secondaire « Copier avec un message » qui copie un texte prêt à envoyer, traduit dans la langue active, par exemple « Voici un lien sécurisé ; il expire le 3 octobre 2026 à 15:42 (UTC+2) et ne peut être ouvert qu’une fois : <lien> », la mention « ne peut être ouvert qu’une fois » n’apparaissant qu’en lecture unique ; la date d’expiration y est toujours absolue, avec le fuseau horaire, car un délai relatif serait faux au moment de la lecture ; ce message ne contient jamais la phrase secrète ni le lien de gestion ;
- sur les appareils qui le permettent, une action de partage natif (Web Share API) limitée au lien de partage ;
- lorsqu’une phrase secrète a été définie, un rappel visible : « Transmettez la phrase secrète par un autre canal que le lien (appel, SMS…) » ;
- le compte à rebours d’expiration ;
- une action Nouveau contenu ; si le lien de gestion n’a jamais été copié ni affiché, elle demande une confirmation indiquant qu’il sera définitivement perdu et qu’il ne sera plus possible de supprimer le contenu avant son expiration ; le même avertissement s’applique avant fermeture ou rechargement de l’écran de résultat ;
- une action de suppression si le jeton de suppression est encore disponible, dans une zone séparée et explicitement identifiée comme dangereuse.

Dès l’affichage de l’écran de résultat, le texte en clair est retiré de l’éditeur, et les références JavaScript au texte, à l’enveloppe, à la phrase secrète, à `K_pass`, `K_enc`, `K_consume_seed` et aux clés privées Ed25519 sont supprimées ; le texte n’est plus affiché pendant le partage du lien. Seuls sont conservés, jusqu’à la sortie de l’écran de résultat, le lien de partage (qui contient `K_url`), le jeton de suppression et les métadonnées affichées (expiration, lecture unique). L’effacement effectif de la mémoire par le moteur JavaScript ne peut pas être garanti.

Le lien de partage doit être le seul lien affiché dans la zone principale. Le lien de gestion ou de suppression doit être placé derrière une action explicite de type « Gérer / Supprimer », dans un panneau ou écran secondaire distinct, avec avertissement d’irréversibilité et confirmation avant révélation ou copie. Les deux liens ne doivent jamais être présentés dans deux champs similaires côte à côte. Le jeton de suppression ne doit jamais être présenté comme une donnée à partager avec le destinataire, ni être inclus dans un QR code ou une action de partage native.

Le QR code doit être généré localement, uniquement à partir du lien de partage, et ne doit jamais contenir le lien de gestion ou de suppression. Il doit utiliser un fond blanc opaque, un contraste élevé et une zone de silence suffisante. Il est masqué par défaut, son affichage et son passage en plein écran nécessitent une action explicite, et aucun service externe ne doit être utilisé pour le générer ou le transmettre.

#### Écran de lecture

- pour une lecture unique, afficher d’abord un écran « Révéler le contenu » qui explique que le message ne pourra être lu qu’une fois et invite à vérifier le contexte (écran partagé, réunion, mauvais appareil) ; aucune requête `open` n’est envoyée avant l’action explicite « Révéler » ; les robots de prévisualisation et les analyseurs de liens qui exécutent le JavaScript ne consomment donc pas le contenu ;
- pour une lecture unique avec phrase secrète, la phrase est demandée et vérifiée localement sur cet écran, avant toute réservation (§6.3.1) ;
- expliquer brièvement que le contenu est déchiffré dans le navigateur ;
- ne jamais afficher un faux contenu ou un aperçu avant la vérification cryptographique ;
- afficher un état de chargement distinct du contenu déchiffré ;
- présenter le contenu dans un conteneur lisible, avec largeur et contraste adaptés ;
- proposer les actions Copier, Masquer et Nouveau contenu ; l’action Masquer est toujours accessible (dans la barre d’action sur mobile), et la touche `Échap`, lorsqu’aucun panneau n’est ouvert, masque immédiatement le contenu, ferme les aperçus et vide la sélection de texte ;
- au-delà de 200 Kio de contenu déchiffré (seuil documenté), la coloration syntaxique et le rendu Markdown enrichi sont désactivés par défaut et proposés sur action explicite, afin de ne pas figer l’interface ; le texte reste affiché en brut ;
- si la consommation échoue avec `404` après un déchiffrement réussi (suppression concurrente par le créateur, par exemple), le contenu déjà déchiffré reste affiché avec un message indiquant qu’il n’est plus disponible sur le serveur ;
- une fois une lecture unique affichée, indiquer par un bandeau que le contenu sera définitivement inaccessible après fermeture, et afficher un avertissement avant fermeture, rechargement ou navigation ;
- masquer automatiquement le contenu (flou et contrôle « Afficher ») après une période d’inactivité, 2 minutes par défaut, réglable par l’utilisateur pour la session ;
- après chaque copie, rappeler sans bloquer qu’il est conseillé d’effacer le presse-papier, le web ne permettant pas de le vider de façon fiable ;
- afficher un bouton Copier dans chaque bloc de code, en plus de l’action globale ; chaque bouton ne doit copier que le bloc concerné et doit être utilisable au clavier et au lecteur d’écran ;
- ne jamais ajouter le contenu copié aux logs, métriques, URL, titres de page, attributs HTML ou messages d’erreur ;
- afficher l’expiration sous forme relative et dynamique, par exemple « Expire dans 14 min », accompagnée de la date et de l’heure locales ; la référence doit être le timestamp d’expiration fourni par le serveur, et non l’heure de création supposée du destinataire ;
- utiliser `expires_at` au format ISO 8601 UTC comme valeur d’autorité ; utiliser `null` pour une durée illimitée ;
- recalculer l’affichage localement sans envoyer le contenu ni la clé ; gérer explicitement les états expiré, échéance proche et horloge locale imprécise ;
- utiliser `server_time` fourni par l’API avec `expires_at` pour corriger l’écart d’horloge lors de l’affichage, selon l’algorithme suivant :
  1. relever `t0 = performance.now()` juste avant l’envoi de la requête et `t1 = performance.now()` à la réception de la réponse ;
  2. estimer l’instant serveur au moment de la réception par `server_time + (t1 − t0) / 2` ;
  3. calculer la durée restante `remaining = expires_at − (server_time + (t1 − t0) / 2)`, indépendamment de l’horloge murale locale ;
  4. décompter ensuite `remaining` avec `performance.now()` (horloge monotone), sans relire `Date.now()` ; `performance.now()` pouvant s’arrêter pendant la veille de l’appareil, le client resynchronise le compte à rebours à chaque événement `visibilitychange` vers l’état visible et à la reprise après veille, par un nouvel appel `status` ou, à défaut, en appliquant l’écart d’horloge murale écoulé ;
  5. si l’aller-retour dépasse un seuil documenté (5 secondes par défaut), afficher l’expiration comme approximative ;
  6. utiliser l’horloge locale uniquement pour convertir l’instant d’expiration en date et heure affichées ;
  7. mettre à jour l’affichage relatif à la minute (à la seconde seulement sous la dernière minute) et n’annoncer aux technologies d’assistance que les seuils de 5 minutes, 1 minute et l’expiration ; les formats relatifs utilisent `Intl.RelativeTimeFormat` ;
- l’expiration effective reste toujours contrôlée par le serveur ; un compte à rebours arrivé à zéro n’autorise ni ne refuse rien par lui-même ;
- ne pas afficher d’éléments marketing ou de ressources externes autour d’un secret ;
- indiquer que le contenu est indisponible lorsqu’il est inexistant, expiré, déjà consommé ou invalide, sans révéler la cause exacte ;
- lorsque le contenu en lecture unique est temporairement réservé par une autre ouverture, afficher au détenteur du lien un message distinct invitant à réessayer après le délai indiqué ; cette distinction est permise car elle n’est accessible qu’après preuve de possession de la clé (§6.3.1) ;
- lorsque le serveur signale des ouvertures antérieures non confirmées (§6.3.1), afficher avant le contenu un avertissement de sécurité indiquant que le lien a déjà été ouvert sans lecture complète ; le message précise que cela peut résulter d’une interception, mais aussi d’un rechargement ou d’une coupure réseau, et recommande, en cas de doute, de considérer le secret comme compromis et de demander à l’expéditeur de le renouveler ;
- hors lecture unique, la validité de la phrase secrète n’est connue qu’à la réussite du déchiffrement ; en lecture unique, elle est vérifiée localement avant réservation (§6.3.1) ; dans les deux cas, une phrase incorrecte produit le message « Phrase secrète incorrecte, réessayez » sans consommer le contenu ;
- si la clé du fragment est absente ou d’une longueur invalide, afficher « Le lien semble incomplet : votre messagerie l’a peut-être coupé. Demandez à l’expéditeur de le renvoyer. », sans contacter le serveur.

#### Mobile et ergonomie tactile

- respecter les zones tactiles minimales de 44 × 44 px ;
- maintenir les actions importantes accessibles avec une seule main ;
- respecter les safe areas des appareils mobiles ;
- éviter les menus ou modales qui empêchent le retour arrière ; préférer des panneaux glissants (*bottom sheets*) fermables par glissement et par le bouton retour ;
- placer l’action principale dans une barre fixée en bas de l’écran, dans la zone du pouce, qui suit le clavier virtuel (`VisualViewport`) et respecte les safe areas ; son contenu dépend de l’écran (création : « Chiffrer et créer le lien » ; résultat : « Copier » ; lecture : « Copier » en action principale et « Masquer » en action secondaire toujours visible) et ne présente qu’une action principale à la fois ; la page applique un `scroll-padding-bottom` égal à la hauteur de la barre, afin qu’elle ne masque jamais l’élément ayant le focus, y compris à 200 % et 400 % de zoom (WCAG 2.2, critère 2.4.11) ;
- ne pas dépendre du survol de la souris ;
- prendre en charge les claviers virtuels et la rotation d’écran ;
- éviter les changements de mise en page pendant la saisie ;
- préserver la position du curseur après une erreur ou un changement de format ;
- appliquer `overscroll-behavior-y: none` uniquement aux conteneurs de l’éditeur et de lecture lorsque cela est nécessaire pour éviter le pull-to-refresh accidentel ; ne pas bloquer globalement les gestes de défilement du document ;
- tester les parcours sur petits écrans, grands écrans et écran tactile.

#### Cohérence visuelle

- utiliser un système de design basé sur des tokens ;
- appliquer les mêmes composants, espacements, libellés et états partout ;
- ne pas modifier la hiérarchie visuelle des alertes de sécurité avec un thème personnalisé ;
- conserver une interface lisible avec toutes les langues activées, qu’elles s’écrivent de gauche à droite (LTR) ou de droite à gauche (RTL) ;
- respecter le mode clair, sombre et le thème système, avec un sélecteur à trois états (Système, Clair, Sombre) ;
- respecter `prefers-reduced-motion` ;
- ne pas utiliser d’animation pour retarder une action critique.

## 6. Fonctionnalités V1

### 6.1 Création d’un contenu

L’utilisateur peut :

- saisir du texte dans un éditeur ;
- coller du texte depuis le presse-papier ;
- sélectionner un langage pour la coloration syntaxique ;
- choisir un format parmi texte brut, Markdown et code ;
- choisir un template Markdown prêt à remplir ;
- définir une expiration ;
- activer la lecture unique ;
- définir une phrase secrète facultative ;
- générer le lien de partage ;
- copier le lien ou afficher un QR code.

Le bouton de publication doit rester désactivé si le chiffrement local n’est pas disponible ou si une erreur de validation est détectée.

### 6.1.1 Templates Markdown

Les templates sont générés et remplis entièrement dans le navigateur. Ils constituent une aide de rédaction et ne doivent jamais ajouter de contenu au serveur avant le chiffrement.

Templates V1 :

- **Identifiants de connexion** : service, URL, nom d’utilisateur, mot de passe, notes ;
- **Clé API ou token** : service, environnement, identifiant, token, date d’expiration, notes ;
- **Connexion Wi-Fi** : réseau, SSID, sécurité, mot de passe, emplacement, notes ;
- **Clé SSH** : serveur, utilisateur, port, empreinte, clé ou chemin local, commande utile ;
- **Base de données** : moteur, hôte, port, base, utilisateur, mot de passe, paramètres ;
- **Variables d’environnement** : nom, valeur, environnement, commentaire ;
- **Accès temporaire** : ressource, bénéficiaire, autorisations, début, fin, procédure de révocation ;
- **Incident technique** : contexte, impact, commandes, logs, actions à effectuer.

Exemple de template « Identifiants de connexion » :

```markdown
# Identifiants de connexion

## Service
- Nom :
- URL :
- Environnement : production / préproduction / développement

## Identité
- Utilisateur :
- Mot de passe :
- Token ou clé associée :

## Sécurité
- Date d’expiration :
- Procédure de rotation :
- Contact :

## Notes
```

Contraintes UX des templates :

- appliquer un template sur un éditeur non vide doit demander une confirmation explicite et proposer une annulation ; le contenu existant ne doit jamais être remplacé silencieusement ;
- aperçu instantané du rendu Markdown ;
- possibilité de modifier tous les champs ;
- possibilité de revenir à un texte vierge ;
- avertissement avant de quitter une saisie non chiffrée ;
- bouton permettant de masquer ou révéler les champs sensibles dans l’éditeur ;
- aucune sauvegarde automatique du contenu en clair dans le serveur ;
- ne pas envoyer le nom du template au serveur en dehors du payload chiffré (§8.2.1) ;
- possibilité d’ajouter des templates personnalisés localement dans une version ultérieure.

### 6.1.2 Absence de honeypots

La V1 ne comporte aucun champ honeypot. Un contenu ne pouvant être créé qu’en produisant un payload chiffré valide par JavaScript, les robots génériques de formulaires ne peuvent pas créer de contenu, et un client automatisé qui appelle l’API n’est pas filtré par un honeypot. La protection contre les abus repose sur le rate limiting, les limites de taille et les quotas de stockage (§7.5).

### 6.2 Lecture d’un contenu

Le destinataire peut :

- ouvrir le lien sans compte ;
- saisir la phrase secrète si elle existe ;
- afficher le contenu après déchiffrement local ;
- basculer entre rendu et source Markdown ;
- pour les templates intégrés, copier chaque valeur individuellement : un bouton Copier sur chaque ligne de la forme « Libellé : valeur » copie uniquement la valeur (priorité Should, §0.3) ;
- pour les templates intégrés, masquer par défaut les valeurs des champs sensibles (mot de passe, token, clé, valeur de variable) avec une action explicite « Afficher » par champ (priorité Should) ;
- reconnaître les templates intégrés et proposer un rendu structuré enrichi (cartes de champs) sans modifier le contenu original (priorité Could) ;
- pour le template Wi-Fi, générer localement, sur action explicite, un QR code de connexion au format `WIFI:T:<sécurité>;S:<SSID>;P:<mot de passe>;;`, dans lequel les caractères `\`, `;`, `,`, `:` et `"` du SSID et du mot de passe sont échappés par une barre oblique inverse ; ce QR code contient un secret et suit les règles d’affichage explicite du QR code de partage, sans jamais être généré automatiquement (priorité Could) ;
- copier le contenu ;
- permettre, si l’option `ui.allow_export` est activée par l’instance, un export strictement local du texte déchiffré, sans upload ni transmission réseau (priorité Could) ;
- afficher avant déchiffrement uniquement les informations publiques : expiration, lecture unique et présence d’une phrase secrète ;
- afficher le format, le langage et le template uniquement après déchiffrement, car ils sont stockés dans l’enveloppe chiffrée (§8.2.1).

Pour les blocs de code, le rendu doit fournir une copie contextualisée bloc par bloc et, lorsque le format le permet, un retour à la ligne activable sans modifier le contenu original. L’affichage de l’expiration doit être localisé dans la langue active et dans le fuseau horaire du navigateur, sans modifier la valeur faisant foi côté serveur.

La page ne doit jamais afficher le contenu avant que le déchiffrement et les contrôles d’intégrité aient réussi.

### 6.3 Gestion du cycle de vie

Durées proposées par défaut :

- 5 minutes ;
- 1 heure ;
- 1 jour ;
- 7 jours ;
- 30 jours ;
- jamais, uniquement si l’instance l’autorise explicitement.

Options :

- suppression après première lecture réussie ;
- expiration automatique ;
- suppression manuelle grâce à un secret de suppression présent dans le lien de gestion ;
- réponse HTTP uniforme lorsque le contenu n’est plus disponible, sans distinguer publiquement un contenu inexistant, expiré ou déjà consommé.

La lecture unique doit être consommée atomiquement côté serveur afin d’éviter deux lectures concurrentes du même contenu.

### 6.3.1 Protocole de lecture et de lecture unique

#### Preuve d’accès (tous les contenus)

Le serveur ne remet jamais un payload chiffré, ni aucune métadonnée, sur la seule connaissance de l’identifiant. Toute ouverture exige une preuve de possession de `K_url` :

1. le client obtient un challenge d’accès, soit par `POST /api/v1/pastes/{id}/challenge`, soit directement dans la page HTML `/p/<id>`, qui peut l’inclure dans une balise `<meta>` afin d’économiser un aller-retour réseau (la page est déjà non cacheable) ;
2. le challenge est sans état et ne nécessite aucune lecture ni écriture du stockage : il est donc produit de façon identique pour un identifiant existant ou non, ce qui empêche l’énumération ;
3. le client signe le message d’accès avec la clé privée d’accès Ed25519 dérivée de `K_url` (§8.2) ;
4. le serveur vérifie, sans accès au stockage, le HMAC, la fraîcheur, l’identifiant et l’usage du challenge, la correspondance entre l’identifiant et `access_pk` transmise, puis la signature avec cette `access_pk` ; il ne lit le stockage qu’ensuite et vérifie que `access_pk` est celle de l’AAD stockée ; tout échec produit la réponse générique d’indisponibilité.

Format du challenge (octets, puis encodé en base64url pour le transport) :

```text
challenge = version (1 octet, 0x01)
          ‖ usage   (1 octet : 0x01 open, 0x02 status, 0x03 consume)
          ‖ id      (24 octets)
          ‖ issued_at (8 octets, secondes Unix, big-endian)
          ‖ nonce   (16 octets aléatoires)
          ‖ mac     (32 octets) = HMAC-SHA-256(K_challenge, tous les octets précédents)

K_challenge = HKDF-SHA-256(IKM = octets décodés de QUIETLINK_APP_SECRET, salt = "sp-proto/v1/hkdf", info = "sp-proto/v1/server/challenge", L = 32)
```

Message signé par le client (Ed25519 pur, sans pré-hachage) :

```text
message = "sp-proto/v1/proof" (octets ASCII) ‖ 0x00 ‖ challenge (82 octets bruts)
```

- l’usage `consume` n’est accepté qu’avec la clé de consommation, les usages `open` et `status` qu’avec la clé d’accès ; un challenge d’un usage n’est jamais accepté pour un autre ;
- les challenges `open` et `status` sont sans état et valables 60 secondes ; lorsqu’un challenge a expiré (par exemple pendant la saisie de la phrase secrète sur l’écran « Révéler »), le client en demande un nouveau par `POST /api/v1/pastes/{id}/challenge` de façon transparente, sans action de l’utilisateur ;
- le challenge `consume` est émis par `open` lors de la réservation et **lié à celle-ci** ; la requête `consume` transmet `access_pk`, vérifiée contre l’identifiant avant tout accès au stockage (§10) ; il est stocké tel quel dans `state.json` avec l’identifiant de réservation (§9.4), ce qui permet de le remettre lors d’une reprise ; il ne constitue pas un secret, puisqu’il est inutilisable sans `K_consume` ; il est vérifié par comparaison en temps constant avec la valeur stockée, et non par HMAC, de sorte qu’une rotation de `QUIETLINK_APP_SECRET` n’affecte pas les réservations en cours ; il n’est valable que pour cette réservation et pendant sa durée restante ; il garde le même format binaire que les autres challenges ;
- la réponse d’émission d’un challenge `open` ou `status` indique sa durée de validité (`expires_in`, en secondes) ; le client le renouvelle de façon proactive avant expiration et, sur toute réponse générique d’indisponibilité (`404`) à `open` ou `status`, refait **une seule** tentative avec un challenge neuf avant d’afficher l’indisponibilité, quel que soit l’âge du challenge initial ; ce mécanisme couvre aussi la rotation du secret d’instance ;
- une tolérance d’horloge nulle s’applique, `issued_at` étant fixé par le serveur lui-même ;
- les vecteurs de test couvrent la construction du message signé ; le format exact du challenge reste opaque pour le client, qui le signe sans l’interpréter ;
- les requêtes `open` et `status` transmettent `access_pk` avec la signature ; le serveur vérifie d’abord, **sans aucun accès au stockage**, le HMAC et la fraîcheur du challenge, l’égalité entre les 8 premiers octets de `id` (`A`) et `tronc64(SHA-256("sp-proto/v1/id" ‖ 0x00 ‖ access_pk))` (§8.2), puis la signature avec cette `access_pk` ; il ne lit le stockage qu’une fois la preuve validée, et compare alors `access_pk` à celle de l’AAD stockée ; un tiers qui ne connaît que l’identifiant ne déclenche donc jamais d’accès disque et ne peut mesurer aucune différence de temps liée à l’existence du contenu ; les tests vérifient l’absence d’accès au stockage pour une preuve invalide.

Conséquences :

- un tiers qui ne connaît que l’identifiant ne peut ni obtenir le ciphertext, ni réserver, ni bloquer une lecture unique, ni savoir si le contenu existe (la création ne permet pas de choisir un identifiant, attribué en partie par le serveur, §8.2) ;
- un détenteur du lien ne peut jamais recréer un contenu sous un lien existant, même après sa suppression, sa consommation ou son expiration : une nouvelle création reçoit toujours un nouvel identifiant, donc un nouveau lien (§8.2) ;
- la preuve d’accès ne dépend pas de la phrase secrète : elle établit la possession du lien, pas la capacité à déchiffrer ;
- un détenteur du lien qui ignore la phrase secrète peut ouvrir une lecture unique protégée et, en répétant l’opération `max_unconfirmed_opens` fois, la détruire ; il ne peut pas la lire ; cette limite est documentée ;
- le rejeu d’une preuve d’accès valide pendant sa fenêtre de 60 secondes ne donne rien de plus que ce que possède déjà le détenteur du lien ; pour la lecture unique, la réservation reste soumise aux règles ci-dessous.

#### Lecture unique

Une lecture unique ne doit pas supprimer le contenu dès sa remise au navigateur, car celui-ci peut échouer avant le déchiffrement.

Le protocole utilise trois états persistants :

```text
available → reserved → consumed
              ↘ timeout → available (compteur d’ouvertures non confirmées +1)
              ↘ timeout avec compteur ≥ seuil → consumed
```

`confirmed` est une étape interne et atomique de la confirmation et ne constitue pas un état persistant.

0. Préparation sans réservation : le client appelle `status` avec une preuve d’accès et obtient l’AAD (dont `kdf` et `consume_pk`), l’état et `unconfirmed_opens`. Si une phrase secrète est requise, l’utilisateur la saisit sur l’écran « Révéler » ; le client dérive `K_pass`, puis `K_consume_seed`, et compare localement la clé publique obtenue à `consume_pk`. En cas d’écart, il affiche « Phrase secrète incorrecte » sans contacter le serveur ni réserver le contenu. Toutes les dérivations coûteuses sont terminées avant l’étape suivante.
1. Après l’action « Révéler », le client génère un identifiant de réservation aléatoire de 128 bits et l’envoie à `open` avec une preuve d’accès valide. Si le contenu est `available`, le serveur le passe sous verrou exclusif à l’état `reserved` en stockant le hash de cet identifiant. Il retourne le payload, un challenge de consommation et le nombre d’ouvertures antérieures non confirmées (`unconfirmed_opens`). Une nouvelle tentative avec le même identifiant de réservation, par exemple après une réponse perdue, reprend la réservation au lieu de recevoir `409`.
2. Une requête concurrente prouvant l’accès reçoit une réponse « réservé » avec le délai restant, sans payload. Une requête sans preuve valide reçoit la réponse générique d’indisponibilité.
3. Le client déchiffre et vérifie le contenu localement. Si `unconfirmed_opens > 0`, l’interface affiche l’avertissement prévu à l’écran de lecture.
4. Le client signe le challenge de consommation avec la clé privée de consommation Ed25519 (`K_consume`, §8.2), déjà dérivée à l’étape 0.
5. Le serveur vérifie la signature avec la clé publique de consommation enregistrée à la création.
6. Le serveur vérifie l’identifiant de réservation sous verrou exclusif, puis passe atomiquement l’état de `reserved` à `consumed` par écriture temporaire suivie d’un renommage atomique ; il enregistre le hash de la signature ayant consommé. `payload.bin` est supprimé sous le même verrou, immédiatement après l’écriture de l’état `consumed` (§9.4.1) ; il n’est plus jamais remis.
7. Une réservation non confirmée est libérée après un délai court et documenté, fixé par défaut à 60 secondes et plafonné par la configuration. Chaque libération incrémente `unconfirmed_opens`.
8. Lorsque `unconfirmed_opens` atteint le seuil `paste.max_unconfirmed_opens` (3 par défaut), le contenu passe à l’état `consumed` au lieu de redevenir disponible.
9. Le hash de l’identifiant de réservation, le challenge `consume` et le hash de la signature ayant consommé restent dans `state.json` après le passage à `consumed`. La consommation est idempotente : un renvoi exact de la même preuve (même réservation, même challenge, même signature), par exemple après une réponse perdue, retourne le même succès tant que l’état `consumed` est conservé ; l’état `consumed` est conservé au moins 10 minutes avant la purge du répertoire ; toute autre réutilisation est rejetée.
10. Les libérations de réservations échues sont appliquées sous verrou à la prochaine requête portant sur le contenu, ou par la purge planifiée (§9.7), selon la première occurrence.

Reprise de réservation après rechargement :

- le client écrit l’identifiant de réservation qu’il a généré dans `sessionStorage` **avant** d’envoyer `open`, et le conserve pendant la durée de la réservation ; cet identifiant n’est ni un contenu ni une clé, et il est effacé dès la consommation ou l’expiration de la réservation ;
- après un rechargement de l’onglet, le client renvoie à `open` une preuve d’accès accompagnée de cet identifiant ; si la réservation est toujours active, le serveur remet à nouveau le payload et le même challenge de consommation, lu dans `state.json`, sans incrémenter `unconfirmed_opens` ;
- la reprise n’est possible que pendant la réservation en cours ; elle ne prolonge pas sa durée ;
- pour un contenu protégé par phrase secrète, l’écran « Révéler » est réaffiché avec le délai de réservation restant et la phrase secrète est redemandée, puis vérifiée localement ; si la réservation expire avant la saisie, elle est libérée et comptée comme ouverture non confirmée, comme toute réservation échue ;
- l’identifiant de réservation n’est jamais écrit dans `localStorage`, un cookie, l’URL ou un log.

Propriétés et limites à documenter :

- un lecteur qui possède le lien peut obtenir et déchiffrer le payload sans confirmer la consommation. La lecture unique ne peut donc pas empêcher une lecture silencieuse par un intercepteur, mais elle garantit que toute lecture non confirmée est **signalée au lecteur suivant** et que le nombre de lectures possibles est borné ;
- un client qui ne possède pas `K_consume` (lien sans phrase secrète correcte) ne peut pas produire de preuve de consommation valide ;
- `consume_pk` permet à un détenteur du lien de tester des phrases secrètes hors ligne sans réserver le contenu ; ce risque est équivalent à celui d’un contenu sans lecture unique, dont le ciphertext peut être testé hors ligne, et il est borné par le coût d’Argon2id et la robustesse de la phrase secrète ;
- la preuve atteste la possession de la clé et la réussite du déchiffrement local ; elle ne prouve pas qu’un humain a effectivement lu ou mémorisé le secret ;
- l’expiration l’emporte sur la réservation : un contenu qui expire pendant une réservation devient indisponible et la confirmation est refusée ; en revanche, un contenu déjà `consumed` est conservé (sans payload) pendant 10 minutes après `terminal_at` même au-delà de son expiration, afin que le rejeu idempotent de `consume` reste possible ; il n’est jamais servi pendant cette période.

Le serveur ne doit jamais recevoir de clé privée, `K_url`, `K_pass` ni la phrase secrète. Les dérivations Ed25519, le challenge HMAC, la durée de réservation, le compteur d’ouvertures non confirmées, les réponses concurrentes et les transitions d’état doivent être couverts par les vecteurs cryptographiques, les tests d’intégration et une revue de sécurité.

### 6.4 Interface mobile

L’interface doit :

- être responsive à partir de 320 px de largeur ;
- supporter les écrans tactiles et les claviers mobiles ;
- proposer des zones de clic d’au moins 44 px ;
- éviter les modales difficiles à fermer au doigt ;
- conserver les actions principales accessibles sans scroll excessif ;
- utiliser un éditeur adapté aux petits écrans ;
- permettre le collage et la copie avec un retour visuel clair ;
- respecter `prefers-reduced-motion` et `prefers-color-scheme` ;
- être utilisable en mode sombre et clair.

### 6.5 Système de thème et personnalisation visuelle

Le thème doit pouvoir être modifié facilement par l’exploitant de l’instance, sans modifier le code métier et sans devoir reconstruire toute l’application.

Le système doit prévoir :

- un thème par défaut clair ;
- un thème sombre ;
- la détection du thème système ;
- des variables CSS centralisées pour les couleurs, espacements, typographies, rayons, ombres et états ;
- des fichiers de thème séparés du code applicatif ;
- une sélection du thème par configuration d’instance ;
- un fichier local de tokens visuels montable dans Docker ;
- un aperçu local avant activation, produit par la commande `php bin/console app:theme:preview --output=<répertoire>` qui génère une page statique de démonstration de tous les composants et états, sans contenu réel (priorité Could) ;
- une documentation indiquant les variables disponibles ;
- une conservation de la compatibilité mobile et de l’accessibilité quel que soit le thème.

Exemple de structure :

```text
config/
└── themes/
    ├── custom-tokens.php.example   # tokens de l’exploitant, jamais servis
    └── custom-tokens.php           # optionnel, monté en lecture seule
public/
├── assets/
│   ├── app.<hash>.css
│   └── app.<hash>.js
└── themes/
    ├── default.<hash>.css          # CSS statique livré avec la release
    ├── dark.<hash>.css

/var/lib/quietlink-generated/
└── tokens.<hash>.css               # généré depuis config/themes/, servi sous /themes/generated/
```

Séparation obligatoire :

- `public/` ne contient que des fichiers statiques (CSS, JS, images, icônes) ; aucun fichier PHP de configuration, de tokens ou d’exemple n’y est placé ;
- les tokens de l’exploitant résident uniquement dans `config/themes/` (§9.5), hors de la racine web ;
- lors de l’amorçage (`app:boot`, §9.5), l’application valide les tokens contre la liste autorisée, puis génère `tokens.<hash>.css` dans le répertoire d’assets générés `storage.generated_assets_dir` (par défaut `/var/lib/quietlink-generated`, volume distinct), seul répertoire d’assets accessible en écriture ; le serveur web le sert en lecture seule sous `/themes/generated/` ; `public/` reste en lecture seule ; aucune valeur de token n’est injectée en style inline, afin de respecter la CSP.

En V1, la personnalisation doit utiliser une liste de tokens validés. Le chargement de CSS arbitraire n’est pas autorisé.

#### Charte graphique de référence V1

La direction artistique de la V1 s’inspire de la palette observée sur [Agillia.shop](https://agillia.shop/) : interface claire, contrastes noir/ivoire, accent terracotta-orange et surfaces pêche très légères. Cette inspiration concerne uniquement l’ambiance colorimétrique ; le nom, le logo, les illustrations et les éléments propriétaires d’Agillia ne doivent pas être repris.

La palette suivante constitue le jeu de tokens de départ. Les valeurs finales doivent être validées par des tests de contraste automatisés et manuels avant livraison :

```css
:root {
    --color-background: #ffffff;
    --color-surface: #f7f6f4;
    --color-surface-accent: #fcece5;
    --color-text: #111111;
    --color-text-muted: #686868;
    --color-border: #e5e1dd;
    --color-border-control: #857f79;
    --color-primary: #c94f26;
    --color-primary-hover: #a83e1d;
    --color-primary-contrast: #ffffff;
    --color-primary-text: #a83e1d;
    --color-focus: #8f2f16;
}
```

Contrastes mesurés (WCAG 2.x) sur les surfaces de départ :

| Couleur | sur `#ffffff` | sur `#f7f6f4` | sur `#fcece5` | Usage autorisé |
|---|---|---|---|---|
| `#c94f26` (primary) | 4,54:1 | 4,20:1 ❌ | 3,95:1 ❌ | fond de bouton avec texte blanc uniquement ; jamais comme couleur de texte |
| `#a83e1d` (primary-text) | 6,23:1 | 5,77:1 | 5,42:1 | liens, texte d’accent et icônes sur toutes les surfaces claires |
| `#686868` (text-muted) | 5,57:1 | 5,16:1 | 4,85:1 | texte secondaire |
| `#8f2f16` (focus) | 8,12:1 | 7,52:1 | 7,06:1 | anneau de focus |
| `#857f79` (border-control) | 3,96:1 | 3,66:1 | 3,44:1 | bordures des champs, cases et contrôles (WCAG 1.4.11, ≥ 3:1) |
| `#e5e1dd` (border) | 1,30:1 | 1,20:1 | — | séparateurs décoratifs uniquement, jamais pour délimiter un contrôle |

L’anneau de focus `#8f2f16` n’offre que 1,79:1 contre le fond du bouton principal `#c94f26` : il est donc toujours tracé avec un décalage (`outline-offset` d’au moins 2 px) afin d’être perçu contre la surface claire environnante, avec laquelle il atteint au moins 7:1.

Le ratio de `#c94f26` avec du texte blanc (4,54:1) est à la limite du seuil AA : toute modification de cette valeur ou de la taille du texte des boutons doit être revérifiée par les tests de contraste automatisés.

Règles d’utilisation :

- utiliser `--color-primary` pour le fond de l’action principale et certains repères de marque, et `--color-primary-text` pour les liens et le texte d’accent ; jamais comme unique signal d’erreur ou d’alerte de sécurité ;
- réserver le noir ou le charbon aux textes et contrôles prioritaires ;
- utiliser l’ivoire, le blanc cassé et le pêche clair pour structurer les surfaces sans diminuer la lisibilité ;
- conserver des couleurs sémantiques distinctes pour les états `success`, `warning`, `danger` et les alertes de sécurité ;
- garantir un contraste WCAG 2.2 AA pour le texte, les contrôles, les bordures utiles et les états de focus ;
- vérifier chaque combinaison de couleur dans les thèmes clair, sombre et personnalisé ;
- ne pas utiliser de dégradé, d’effet décoratif ou d’animation pour véhiculer une information de sécurité.

Le thème sombre doit conserver l’accent terracotta avec une luminosité adaptée, sans utiliser l’orange clair comme couleur de texte courant. Il doit notamment prévoir un fond charbon, une surface sombre distincte, un texte clair, un texte secondaire lisible et des bordures visibles. Les tokens du thème sombre doivent être testés séparément ; ils ne doivent pas être déduits automatiquement par simple inversion des couleurs.

La typographie doit privilégier une pile système locale sans appel à une police distante. Une police sans empattement est utilisée pour l’interface et une pile monospace pour le contenu de type code, les secrets structurés et les logs. La palette ne doit jamais prendre le pas sur la hiérarchie des actions, la lisibilité ou la visibilité des alertes.

Le logo QuietLink associe un bouclier terracotta et deux maillons entrelacés, suivi du mot-symbole « Quiet » en couleur de texte et « Link » en couleur d’accent. Il est fourni en SVG (`docs/brand/logo.svg` et `docs/brand/logo-dark.svg`) dans une version claire et une version sombre ; dans l’interface, le symbole est intégré en SVG en ligne coloré par les tokens du thème (`--ql-color-primary` pour le bouclier), ce qui fournit la version sombre sans fichier supplémentaire et respecte les thèmes personnalisés. Le symbole est décoratif (`aria-hidden`) : le nom accessible du lien d’accueil reste le nom de l’instance (`app.name`). Le même symbole sert d’icône de favori (`/favicon.svg`), servi par l’instance.

Exigences de sécurité :

- les thèmes personnalisés doivent être servis uniquement depuis l’instance ;
- aucune feuille de style distante ne doit être chargée par défaut ;
- seuls les tokens visuels explicitement autorisés peuvent être personnalisés ;
- aucun chemin de fichier arbitraire ne doit être accepté par la configuration ;
- les thèmes ne doivent pas permettre l’injection de JavaScript ;
- les URLs `url()` externes doivent être interdites ou contrôlées ;
- les thèmes ne doivent jamais modifier, masquer ou rendre ambiguës les alertes de sécurité ;
- le thème actif ne doit pas être enregistré dans les contenus chiffrés ;
- le changement de thème ne doit pas exposer le contenu non chiffré.

L’interface doit séparer clairement :

- le thème visuel de l’instance ;
- le choix clair/sombre de l’utilisateur ;
- les couleurs réservées aux états de sécurité, erreurs et avertissements.

### 6.6 Accessibilité

Objectif : WCAG 2.2 niveau AA pour les parcours principaux.

Exigences :

- navigation complète au clavier ;
- libellés accessibles pour les contrôles ;
- contraste conforme ;
- focus visible ;
- messages d’erreur associés au champ concerné ;
- aucun contenu transmis uniquement par la couleur ;
- compatibilité avec les lecteurs d’écran courants ;
- textes d’interface en français et en anglais au minimum.

### 6.6.1 Internationalisation

L’application doit être multilingue dès la V1.

Règles de sélection de la langue :

1. utiliser la langue choisie explicitement par l’utilisateur si elle est activée ;
2. sinon détecter la langue préférée du navigateur ;
3. utiliser cette langue si elle est supportée par l’instance ;
4. sinon utiliser l’anglais.

L’anglais (`en`) est la langue par défaut et le fallback obligatoire. La V1 fournit les langues suivantes : anglais (`en`), français (`fr`), espagnol (`es`), italien (`it`) et arabe (`ar`). L’ajout de nouvelles langues doit être possible en ajoutant un catalogue de traductions, sans modifier le code métier : les catalogues présents sont découverts automatiquement, activés par défaut et restreints au besoin par `app.enabled_locales` ; les catalogues autres que l’anglais et le français sont chargés à la demande, sans alourdir le premier affichage.

L’application doit accepter les langues écrites de gauche à droite (LTR) **et** de droite à gauche (RTL). Chaque catalogue de traduction doit déclarer sa direction (`ltr` ou `rtl`) et l’interface doit appliquer cette direction au document HTML (pages servies par le serveur, pages d’erreur, changement de langue côté client) et aux composants concernés. En RTL, toute la mise en page est miroir ; le contenu saisi ou déchiffré prend sa propre direction (`dir="auto"`), tandis que les liens, identifiants et blocs de code restent LTR.

Exigences :

- traduire tous les textes d’interface, erreurs, états de chargement et messages de sécurité ;
- utiliser des catalogues de traduction versionnés ;
- ne jamais concaténer des fragments de phrases dans le code ;
- gérer correctement les accents, la casse, les dates et les formats locaux ;
- ne pas traduire les données secrètes ou le contenu déchiffré de l’utilisateur ;
- ne pas envoyer le contenu du paste pour déterminer la langue ;
- permettre à l’utilisateur de changer la langue depuis l’interface ;
- mémoriser uniquement la préférence de langue, jamais le contenu en clair ;
- utiliser `dir` (`ltr` ou `rtl`, selon le catalogue) et `lang` correctement sur le document HTML ;
- utiliser les propriétés CSS logiques comme `margin-inline`, `padding-inline` et `text-align: start` ;
- ne pas coder en dur des positions gauche/droite dans les composants réutilisables ;
- conserver l’alignement, les boutons et les raccourcis cohérents avec la direction de la langue (LTR ou RTL) ; les éléments graphiques directionnels (flèches, icônes, jauges) sont mis en miroir en RTL ;
- tester l’interface avec une langue longue, une langue utilisant des caractères non latins et une langue RTL ;
- faire relire chaque catalogue par un locuteur natif avant une publication publique.

### 6.7 API et CLI

La V1 doit exposer une API minimale permettant :

- de créer un contenu déjà chiffré ;
- de récupérer le payload chiffré après preuve de possession de la clé du lien (§6.3.1) ;
- de supprimer un contenu avec son jeton de suppression ;
- de vérifier l’état d’un contenu (expiration, lecture unique, état de réservation) après preuve de possession de la clé du lien, sans récupérer le payload ni réserver une lecture unique.

La CLI doit permettre des flux distincts pour les métadonnées et le déchiffrement :

```sh
printf 'secret' | quietlink create --expires 1h
quietlink metadata --url-stdin
quietlink decrypt --url-stdin
```

Les commandes doivent accepter une URL via l’entrée standard afin d’éviter son exposition dans l’historique shell. `metadata` ne doit jamais déchiffrer ni afficher le contenu, ni réserver une lecture unique ; `decrypt` doit écrire le texte uniquement sur la sortie standard ou dans un fichier explicitement demandé par l’utilisateur. Pour une lecture unique, `decrypt` suit le protocole de §6.3.1 (vérification locale de la phrase secrète avant réservation) et **consomme** le contenu après un déchiffrement réussi ; la commande l’indique avant d’agir et demande une confirmation sur un TTY, sauf option explicite `--yes`. Il n’existe pas d’option permettant de lire sans consommer.

Saisie de la phrase secrète dans la CLI :

- par défaut, invite interactive sans écho, avec confirmation à la création ; les invites (phrase secrète, confirmation de consommation) lisent et écrivent toujours sur le terminal de contrôle (`/dev/tty`), jamais sur l’entrée standard, qui peut être un tube transportant l’URL ou le texte ;
- `--passphrase-stdin` peut être utilisé explicitement pour les usages non interactifs ; une seule donnée peut être lue sur l’entrée standard par commande ; les combinaisons admises sont : `create` avec le texte sur l’entrée standard et la phrase secrète par `/dev/tty` ou `--passphrase-file` ; `create --passphrase-stdin` avec le texte lu par `--input <fichier>` ; `decrypt`/`metadata --url-stdin` avec la phrase secrète par `/dev/tty` ou `--passphrase-file` ; toute autre combinaison (notamment `--url-stdin` avec `--passphrase-stdin`) est refusée avec un message explicite ;
- `--passphrase-file <chemin>` est accepté si le fichier n’est lisible que par son propriétaire, ou s’il se trouve sous `/run/secrets/` (Docker Secrets, montés en lecture pour tous à l’intérieur d’un conteneur isolé) ; sinon la commande refuse de démarrer ;
- toute option ou variable d’environnement transmettant la phrase secrète en clair dans la ligne de commande (`--passphrase=...`, `QUIETLINK_PASSPHRASE`) est interdite ;
- sans terminal de contrôle et sans option explicite, une commande nécessitant une phrase secrète échoue avec un message clair ; de même, `decrypt` d’une lecture unique sans terminal de contrôle et sans `--yes` refuse de consommer et échoue ; l’image Docker de la CLI documente l’usage de `docker run -it` pour les invites interactives ;
- la phrase secrète et les clés dérivées sont effacées au mieux avec `sodium_memzero()` dès qu’elles ne sont plus nécessaires ; PHP pouvant conserver des copies internes des chaînes, l’effacement effectif de toutes les copies n’est pas garanti.

La CLI doit afficher séparément le lien de partage et le lien de gestion, avec un avertissement explicite sur le caractère destructif du second. Elle ne doit jamais les concaténer, les envoyer ensemble ou les inscrire dans les logs.

La CLI doit être développée en PHP et ne doit jamais envoyer de texte en clair à une API qui prétend assurer le chiffrement côté client. Elle doit chiffrer localement avant l’envoi.

La CLI doit être distribuée :

- sous forme de PHAR versionné pour les environnements disposant de PHP ;
- sous forme d’image Docker éphémère pour les environnements ne disposant pas de PHP ;
- avec les mêmes vecteurs de test et le même format cryptographique que le frontend.

La CLI respecte la même idempotence que le frontend (§10) : une nouvelle tentative après un timeout réutilise exactement la même `Idempotency-Key` et le même corps de requête octet pour octet. Sur une réponse `422`, elle n’effectue aucune nouvelle tentative et signale une erreur interne. Elle vérifie les empreintes `A` et `D` de l’identifiant reçu avant d’afficher les liens (§8.2).

Un PHAR est un paquet PHP et nécessite toujours un runtime PHP ; il ne doit pas être présenté comme un binaire autonome.

### 6.8 Impression et export local

Priorité Could (§0.3). L’impression n’est pas une fonction activée par défaut en V1, car elle crée une copie durable du contenu déchiffré dans une imprimante, une file d’impression, un PDF ou un système d’exploitation. Elle est contrôlée par l’option `ui.allow_print` (défaut `false`) ; l’export local est contrôlé par `ui.allow_export` (défaut `false`) et suit les mêmes règles d’action explicite et d’avertissement.

Lorsque `ui.allow_print` vaut `false`, aucune action d’impression n’est affichée et la feuille `@media print` masque le contenu déchiffré.

Si l’exploitant active explicitement cette fonction :

- l’impression doit être déclenchée uniquement par une action explicite de l’utilisateur ;
- un avertissement doit rappeler que le contenu sort du périmètre de protection de l’application ;
- aucune impression automatique, ouverture automatique de la boîte d’impression ou génération de PDF ne doit être effectuée ;
- la feuille `@media print` doit masquer les contrôles, menus, QR codes, liens de gestion, en-têtes et éléments décoratifs ;
- seul le contenu déchiffré demandé par l’utilisateur peut être imprimé, sans métadonnée sensible ni jeton de suppression ;
- l’interface ne doit pas présenter l’impression comme une opération sans risque.

### 6.9 Liens externes dans le Markdown

Cette section fait référence pour les liens ; §7.5 « Markdown et contenu rendu » y renvoie. Les liens rendus depuis le contenu déchiffré sont des données non fiables :

- seuls les schémas `https` et `http` sont autorisés en V1 ;
- les schémas `javascript:`, `data:`, `file:` et les schémas personnalisés doivent être bloqués ;
- les liens externes doivent afficher un indicateur accessible de sortie de l’application et, lorsque cela est possible, le domaine cible ;
- l’indicateur ne doit jamais être présenté comme une validation de confiance ou de sécurité du domaine ;
- `noopener` et `noreferrer` doivent être appliqués aux ouvertures dans un nouvel onglet ;
- aucune prévisualisation, résolution DNS, requête HEAD ou récupération automatique de la cible ne doit être effectuée ;
- une confirmation supplémentaire peut être affichée pour les liens `http` non chiffrés ou selon une politique de l’instance, mais elle ne doit pas apparaître à chaque clic sur un lien HTTPS légitime.

### 6.10 Installation web et PWA

La V1 ne fournit pas de mode hors ligne ni de Service Worker. Un `manifest.json` minimal (priorité Could, option `ui.enable_manifest`, défaut `false`) peut être fourni pour les icônes et l’ajout manuel à l’écran d’accueil, sous réserve des règles suivantes :

- aucun payload, contenu déchiffré, clé, phrase secrète ou réponse API ne doit être mis en cache pour rendre l’application installable ;
- aucun Service Worker ne doit être livré en V1 ;
- aucun écran d’installation ne doit être imposé à l’utilisateur ;
- le mode d’affichage doit conserver l’origine visible dans le navigateur ; `display: standalone` est interdit en V1 ;
- le manifest et les icônes ne doivent contenir aucune donnée liée à un secret ou à une instance sensible ;
- l’ajout à l’écran d’accueil ne doit pas modifier les règles de `Cache-Control`, de CSP ou de non-persistance des contenus.

## 7. Modèle de sécurité

### 7.1 Propriété de sécurité principale

Le serveur ne doit pas pouvoir déchiffrer le contenu stocké, même s’il accède au stockage de fichiers contenant les payloads.

Cette propriété ne protège pas contre une instance qui servirait un JavaScript malveillant. Ce risque doit être clairement documenté et réduit par les mesures de distribution et de déploiement décrites ci-dessous.

### 7.2 Menaces couvertes

- lecture du répertoire de stockage par un attaquant ;
- fuite de sauvegardes chiffrées ;
- accès abusif à un identifiant de contenu sans clé ;
- altération du payload détectable ;
- attaque par rejeu sur les endpoints ;
- brute force massif sur les identifiants ;
- obtention du ciphertext, réservation ou blocage d’une lecture unique par un tiers ne connaissant que l’identifiant ;
- lecture non confirmée d’un contenu en lecture unique, signalée au lecteur suivant (§6.3.1) ;
- saturation du stockage par création massive de contenus ;
- scraping et abus automatisé ;
- exposition accidentelle du contenu dans les logs applicatifs ;
- concurrence sur la lecture unique ;
- contenu HTML ou Markdown dangereux.

### 7.3 Menaces non couvertes

- serveur ou CDN compromis qui modifie le JavaScript envoyé au navigateur ;
- appareil compromis ou navigateur malveillant ;
- substitution de contenu par un détenteur du lien disposant d’un accès en écriture au stockage, `K_enc` dérivant de `K_url` ; modification des dates (`created_at`, `expires_at`) par le serveur ou le stockage, ces dates n’étant pas authentifiées par l’AAD ;
- attaque par canal auxiliaire temporel locale contre le module Ed25519 JavaScript de repli, qui n’est pas garanti à temps constant ; le risque est limité à un attaquant capable de mesurer finement l’exécution sur l’appareil, et Web Crypto est préféré lorsqu’il est disponible ;
- capture d’écran ou copie faite par le destinataire ;
- fuite du lien complet dans l’historique, une capture ou un outil de prévisualisation ;
- phrase secrète faible divulguée ;
- corrélation par IP et horodatage si l’instance conserve des logs réseau ;
- disponibilité de l’instance en cas de panne ou de déni de service.

### 7.4 Modèle de confiance

L’utilisateur fait confiance :

- au code client qu’il exécute ;
- à son navigateur et à son appareil ;
- à la sécurité du transport HTTPS ;
- à l’instance pour stocker et servir un ciphertext sans le modifier de façon indétectable.

L’utilisateur ne doit pas avoir à faire confiance à l’instance pour connaître le texte en clair.

### 7.5 Niveau de sécurité renforcé

La V1 doit viser un niveau de sécurité adapté à la production et aux données confidentielles. Le produit ne doit pas revendiquer une sécurité absolue ni une certification qui n’a pas été obtenue.

#### Durcissement HTTP et navigateur

Toutes les réponses applicatives doivent appliquer, au minimum :

- HTTPS obligatoire en production ;
- HSTS avec durée documentée ;
- `Content-Security-Policy` stricte, sans `unsafe-inline` ni `unsafe-eval`, dont la politique de référence V1 est :

  ```text
  default-src 'none';
  script-src 'self';
  worker-src 'self';
  style-src 'self';
  img-src 'self' data:;
  font-src 'self';
  connect-src 'self';
  manifest-src 'self';
  form-action 'none';
  base-uri 'none';
  frame-ancestors 'none';
  object-src 'none';
  upgrade-insecure-requests
  ```

  Le module Argon2id WebAssembly s’exécute **uniquement dans un Web Worker dédié** ; la politique de la page ne contient donc jamais `'wasm-unsafe-eval'`, qui figure seulement dans l’en-tête CSP de la réponse du script de ce worker, servi en permanence afin que les contenus protégés par phrase secrète restent lisibles même si `paste.allow_passphrase` est désactivé ensuite (cette option ne concerne que la création). Le bloc ci-dessus est la politique de la page ; le script du worker Argon2id est servi avec la même politique, `script-src` étant complété par `'wasm-unsafe-eval'` ; ces deux politiques sont vérifiées par les tests. Les Web Workers sont chargés uniquement depuis des fichiers de même origine (pas de `blob:` ni de `data:`). Un worker applique la CSP de **sa propre réponse HTTP** et non celle de la page : les scripts de workers doivent donc être servis avec un en-tête CSP incluant `'wasm-unsafe-eval'` lorsqu’ils chargent le module WebAssembly, et ce point est vérifié par les tests. `style-src 'self'` interdit les attributs `style` présents dans le HTML : la coloration syntaxique, l’assainisseur Markdown et le rendu du QR code doivent n’utiliser que des classes CSS, et l’assainisseur supprime tout attribut `style`. La modification de styles par l’API CSSOM (`element.style.setProperty`), qui n’est pas soumise à cette directive, reste permise pour le code applicatif (barre suivant `VisualViewport`, jauge de taille). `img-src data:` n’est autorisé que pour le QR code généré localement ; il peut être retiré si le QR code est rendu en SVG inline construit via le DOM. `manifest-src` n’est présent que si `ui.enable_manifest` est activé. L’ajout de `require-trusted-types-for 'script'` avec une politique Trusted Types dédiée au rendu Markdown est recommandé (SHOULD). Toute modification de cette politique doit faire l’objet d’une revue de sécurité ;
- `connect-src` limité à l’origine de l’instance ;
- `frame-ancestors 'none'` et interdiction d’intégration en iframe ;
- `X-Content-Type-Options: nosniff` ;
- `Referrer-Policy: no-referrer` ;
- `Cache-Control: no-store` sur les pages et réponses liées aux contenus ;
- `Permissions-Policy` désactivant les capacités non nécessaires, avec au minimum `camera=(), microphone=(), geolocation=(), payment=(), usb=()` et `clipboard-read=(self), clipboard-write=(self)` ; le presse-papier n’est pas régi par la CSP, et sa lecture reste en outre soumise à l’action explicite de l’utilisateur et à l’autorisation du navigateur (§5.1) ;
- `Cross-Origin-Opener-Policy` et `Cross-Origin-Resource-Policy` configurés ;
- aucune ressource tierce, police distante, analytics ou publicité ;
- cookies désactivés par défaut.

Le code JavaScript livré au navigateur doit être statique, versionné et produit par un build contrôlé. Les scripts inline doivent être évités ; lorsqu’ils sont indispensables, ils doivent utiliser un nonce ou un hash CSP généré par le serveur.

#### Frontend de confiance

Le projet doit fournir :

- builds reproductibles autant que possible ;
- hashes des fichiers frontend publiés avec chaque release ;
- signature des artefacts de release (image Docker, PHAR, archives, fichier des hashes du frontend) avec Sigstore (`cosign`, signature sans clé via OIDC GitHub Actions, journal de transparence Rekor) et attestations de provenance SLSA produites par GitHub ;
- génération d’un SBOM ;
- vérification des dépendances frontend et PHP en CI ;
- absence de dépendance chargée dynamiquement depuis une URL distante ;
- procédure documentée pour vérifier l’intégrité d’une release.

Le modèle de menace doit rappeler qu’un serveur compromis capable de modifier le JavaScript peut tenter de récupérer une clé au moment du déchiffrement. Cette limite doit être visible dans la documentation utilisateur et administrateur.

#### Cryptographie

- aucune primitive cryptographique maison ;
- génération aléatoire avec une source cryptographiquement sûre ;
- nonces uniques garantis pour chaque clé et chaque chiffrement ;
- comparaison constante dans les vérifications de secrets côté PHP ;
- format cryptographique versionné et spécifié avant développement ;
- vecteurs de test communs au frontend, au backend et à la CLI ;
- revue cryptographique obligatoire avant la bêta publique ;
- impossibilité de rétrograder silencieusement l’algorithme ou le format ;
- effacement des références aux secrets en mémoire lorsque la plateforme le permet ;
- aucun secret de test ou vecteur contenant une donnée réelle.

#### Backend PHP et stockage

- mode production obligatoire, erreurs détaillées désactivées ;
- exceptions converties en réponses génériques et journalisées sans secret ;
- aucun chemin de fichier construit à partir d’une entrée non validée : l’identifiant est validé (exactement 32 caractères base64url canoniques, décodés en 24 octets) avant toute dérivation de chemin et avant les contrôles de `A` et `D`, et le chemin final est vérifié comme inclus dans `storage.root_dir` ;
- validation stricte des types, tailles et encodages ;
- limites de temps et de mémoire sur chaque requête ;
- identifiants de 192 bits attribués par le serveur (§8.2) ;
- verrouillage exclusif et écriture atomique pour la lecture unique ;
- compte système dédié avec permissions minimales sur le répertoire de stockage ;
- stockage des payloads uniquement sous forme chiffrée ;
- sauvegardes traitées comme des données sensibles, même si elles contiennent uniquement des ciphertexts ;
- tâches de nettoyage idempotentes et vérifiables ;
- aucun accès direct du serveur web au répertoire de configuration ou aux secrets.

#### Isolation d’exécution

Le déploiement Docker doit appliquer, autant que possible :

- utilisateur non root (obligatoire, sans exception) ;
- filesystem en lecture seule ;
- suppression des capabilities Linux non nécessaires ;
- profil seccomp ou équivalent ;
- réseau sortant limité aux dépendances indispensables ;
- image minimale et versionnée par digest ;
- secrets injectés au runtime, jamais intégrés à l’image ;
- séparation des volumes applicatifs et des données ;
- healthcheck ne révélant aucune information sensible.

#### Markdown et contenu rendu

- rendu par markdown-it, avec HTML brut désactivé (`html: false`) et validation des liens personnalisée (§6.9), puis assainissement du HTML produit par DOMPurify avec une allowlist explicite et la prise en charge de Trusted Types ; ces bibliothèques sont versionnées et incluses dans le SBOM frontend ;
- configurer l’assainissement avec une allowlist explicite et testée ;
- HTML brut désactivé par défaut ;
- sanitation par allowlist ;
- scripts, iframes, objets et événements HTML interdits ;
- aucune image n’est rendue depuis le contenu Markdown en V1, quelle que soit sa source (distante, `data:`, `blob:` ou relative) : une image Markdown est affichée sous forme de texte alternatif suivi de son URL en texte brut non cliquable ;
- liens externes traités selon §6.9 (allowlist `http`/`https`, blocage des autres schémas, `noopener`/`noreferrer`, indicateur accessible, ouverture uniquement sur action explicite) ;
- aucune prévisualisation réseau automatique ;
- tests XSS dédiés sur le Markdown, les templates et les erreurs de déchiffrement.

#### Anti-abus et disponibilité

- rate limiting séparé pour la création, l’émission de challenges, l’ouverture, le statut, la consommation, la suppression et la santé ; l’ouverture et le statut sont aussi limités par identifiant de contenu, mais **seules les requêtes portant une preuve d’accès valide** sont comptées dans cette limite par identifiant, afin qu’un tiers ne connaissant que l’identifiant ne puisse pas bloquer la lecture légitime ; les preuves invalides ne sont limitées que par adresse IP ;
- limites de taille appliquées avant le parsing complet du corps ;
- protection contre les requêtes lentes et les connexions maintenues abusivement ;
- refus des formats et encodages non supportés ;
- réponses d’erreur ne permettant pas d’énumérer les contenus ;
- mesures anti-déni de service documentées ;
- aucun quota utilisateur, mais des limites techniques anti-abus obligatoires ;
- quota global de stockage (`storage.max_total_bytes`) et nombre maximal de contenus actifs (`storage.max_items`) : au-delà, la création est refusée avec une réponse `503` générique accompagnée d’un en-tête `Retry-After` ; ces quotas reposent sur un compteur persistant (`usage.json`) mis à jour sous verrou à chaque création et suppression, et recalculé intégralement par la purge (§9.7) pour corriger toute dérive ; aucun parcours du stockage n’est effectué pendant une requête ;
- seuil minimal d’espace disque libre (`storage.min_free_bytes`) vérifié avant chaque création avec `disk_free_space()` ;
- seuil minimal d’inodes libres (`storage.min_free_inodes_percent`) vérifié par la purge et par `app:boot`, PHP n’exposant pas ce nombre : la mesure utilise `df -P -i` sur le volume de stockage, exécuté uniquement en CLI ; le résultat est écrit dans `health.json`, horodaté et lu par la création, qui refuse les nouveaux contenus tant que le seuil n’est pas respecté ; un `health.json` de plus de 10 minutes est signalé comme dégradé par `/healthz` et dans les logs, et la création est alors refusée (`503`) jusqu’à sa mise à jour, l’état réel de l’espace et des inodes étant inconnu ; `storage.max_items` borne par ailleurs le nombre d’inodes consommés (au plus 8 par contenu en comptant le fichier d’état temporaire et la part des répertoires de partition) ;
- durée maximale de conservation globale (`paste.max_retention`) appliquée à tous les contenus ; elle est incompatible avec l’option « jamais » (§9.5) ;
- `paste.max_unconfirmed_opens` est compris entre 1 et 10 ;
- alerte opérationnelle documentée lorsque 80 % d’un quota est atteint.

#### Gouvernance sécurité

- analyse de menace conservée avec le code ;
- revue de sécurité à chaque modification du protocole ou du rendu ;
- analyse automatisée des dépendances à chaque changement ;
- politique de divulgation de vulnérabilités publiée ;
- procédure de réponse aux incidents ;
- audit de sécurité avant production ;
- audit externe ciblé obligatoire avant la première version publique, portant sur le format cryptographique, les vecteurs de test, le protocole de lecture et de lecture unique et le code client de chiffrement ; le reste du périmètre est couvert par une revue interne formalisée, l’analyse SAST/DAST et les tests ; un test d’intrusion complet reste optionnel.

## 8. Conception cryptographique

### 8.1 Règles générales

- Utiliser uniquement les primitives cryptographiques fournies par une bibliothèque reconnue ou par Web Crypto API.
- Ne jamais implémenter soi-même AES, HKDF, HMAC ou un générateur aléatoire.
- Utiliser un générateur aléatoire cryptographiquement sûr.
- Versionner explicitement le format chiffré.
- Prévoir des vecteurs de test publics et déterministes.
- Refuser tout downgrade silencieux d’algorithme ou de version.

### 8.2 Format cible V1

Le format cryptographique doit être figé en Phase 0 avec des vecteurs de test avant toute implémentation frontend, backend ou CLI.

Pour chaque contenu :

1. générer une clé URL aléatoire `K_url` de 256 bits et la placer dans le fragment URL, jamais dans la partie envoyée au serveur ;
2. dériver la paire d’accès Ed25519 ; l’identifiant public de 192 bits (24 octets) n’est pas choisi par le client : il est attribué par le serveur à la création sous la forme `id = A ‖ D ‖ R`, avec `A = tronc64(SHA-256("sp-proto/v1/id" ‖ 0x00 ‖ access_pk))`, `D = tronc64(SHA-256("sp-proto/v1/id-delete" ‖ 0x00 ‖ deletion_hash))` et `R` un aléa de 8 octets tiré par le serveur (`random_bytes`) et unique parmi les contenus existants, où `tronc64(x)` désigne les 8 premiers octets de `x` ; les vecteurs de test couvrent le calcul de `A` et de `D` ;
   - `A` lie l’identifiant à `access_pk`, ce qui permet de vérifier une preuve d’accès avant tout accès au stockage (§6.3.1) : forger une preuve pour un identifiant donné sans `K_url` exigerait de trouver une clé dont l’empreinte coïncide sur 64 bits, soit environ 2^64 essais ;
   - `D` lie l’identifiant au jeton de suppression, ce qui permet de vérifier une requête `DELETE` avant tout accès au stockage (§10) : un tiers qui connaît l’identifiant sans le jeton ne déclenche aucun accès disque et ne peut donc mesurer aucune différence liée à l’existence du contenu ;
   - `R`, aléatoire et choisi par le serveur, garantit qu’une nouvelle soumission, même par un détenteur du lien et avec les mêmes octets, produit toujours un nouvel identifiant : aucun contenu ne peut être recréé ou remplacé sous un lien existant, et la création ne fournit aucun oracle d’existence ; aucun mécanisme ne réattribue un identifiant, y compris une nouvelle tentative idempotente (§10) ; après la suppression d’un contenu, un nouveau tirage ne pourrait reproduire son identifiant qu’avec une probabilité de 2^-64 par création portant les mêmes `access_pk` et `deletion_hash` ;
   - à la réception de l’identifiant, le client (navigateur ou CLI) vérifie `A` et `D` avant de construire les liens ; à l’ouverture d’un lien de partage, le lecteur vérifie `A`, et à l’ouverture d’un lien de gestion, la page vérifie `D`, avant tout appel réseau, et signale un lien altéré sinon ;
   - à la création, le serveur rejette les clés `access_pk` et `consume_pk` non canoniques ou d’ordre faible (vérification fournie par libsodium lors de la vérification de signature, et contrôle explicite à la création) ;
3. si une phrase secrète est utilisée, générer un sel aléatoire de 16 octets et dériver `K_pass` avec Argon2id (§8.3) ;
4. dériver les clés selon le schéma de dérivation ci-dessous ;
5. construire l’enveloppe en clair (§8.2.1) ;
6. générer un nonce aléatoire de 96 bits ; `K_enc` étant unique par contenu et n’étant utilisée que pour un seul chiffrement, l’unicité du couple clé/nonce est garantie ;
7. chiffrer l’enveloppe avec AES-256-GCM sous `K_enc`, avec comme données associées les octets de l’AAD canonique (§8.2.2) ; le tag de 128 bits est concaténé à la fin du ciphertext ;
8. générer les paires Ed25519 d’accès et, en lecture unique, de consommation, et n’envoyer que leurs clés publiques ;
9. générer localement un jeton de suppression aléatoire de 256 bits, envoyer uniquement `SHA-256(jeton)` au serveur et conserver le jeton pour construire le lien de gestion ;
10. envoyer au serveur uniquement : le ciphertext, le nonce, l’AAD canonique, le hash du jeton de suppression et les métadonnées publiques qu’elle contient.

#### Schéma de dérivation des clés

Chaque clé a un usage unique ; aucune clé n’est utilisée à la fois pour chiffrer et pour signer, ni comme matériau d’une autre dérivation en dehors de ce schéma.

```text
salt_hkdf     = "sp-proto/v1/hkdf"                               (octets ASCII)

PRK_url       = HKDF-Extract-SHA-256(salt_hkdf, K_url)
K_access_seed = HKDF-Expand(PRK_url, "sp-proto/v1/access/ed25519", 32)

IKM_content   = K_url || K_pass        avec phrase secrète
              = K_url                  sans phrase secrète
PRK_content   = HKDF-Extract-SHA-256(salt_hkdf, IKM_content)
K_enc         = HKDF-Expand(PRK_content, "sp-proto/v1/content/aes-256-gcm", 32)
K_consume_seed= HKDF-Expand(PRK_content, "sp-proto/v1/read-once/consume/ed25519", 32)
```

- `K_access_seed` et `K_consume_seed` sont des graines Ed25519 de 32 octets (RFC 8032) ; les clés privées ne sont jamais stockées et sont redérivées par le lecteur ;
- `K_access` ne dépend que du lien : elle prouve la possession du lien (§6.3.1) ;
- `K_consume` dépend de la phrase secrète éventuelle : elle prouve la capacité à déchiffrer ;
- en PHP, chaque ligne Extract + Expand correspond à `hash_hkdf('sha256', IKM, 32, info, salt_hkdf)` ; en Web Crypto, à `deriveBits` avec l’algorithme HKDF ;
- les vecteurs de test couvrent chaque valeur intermédiaire (`PRK_*`, graines, clés publiques).

#### 8.2.1 Enveloppe chiffrée

Le texte chiffré n’est pas le texte brut seul, mais une enveloppe JSON encodée en UTF-8. L’enveloppe étant chiffrée, elle n’a pas besoin d’être canonique : tout JSON strictement valide est accepté (UTF-8 valide, pas de clé dupliquée, pas de clé inconnue). Elle n’est jamais vérifiée octet à octet :

```json
{"format":"markdown","language":null,"template":"credentials","text":"…","v":1}
```

- `format` ∈ `plain`, `markdown`, `code` ;
- `language` : identifiant de coloration syntaxique ou `null` ;
- `template` : identifiant de template intégré ou `null` ;
- `text` : contenu saisi, en UTF-8 normalisé uniquement pour les fins de ligne (`\n`) ; avant sérialisation, le navigateur remplace les surrogates UTF-16 isolés par U+FFFD (`String.prototype.toWellFormed()`), faute de quoi le JSON produit serait refusé par `json_decode` côté CLI ;
- `v` : version de l’enveloppe.

Le format, le langage et le template ne figurent jamais en clair côté serveur.

La limite de taille saisie par l’utilisateur porte sur l’enveloppe sérialisée, et non sur le texte brut : l’échappement JSON peut multiplier la taille de certains caractères (jusqu’à 6 octets pour un caractère de contrôle). La jauge de l’éditeur (§5.1) mesure donc la taille de l’enveloppe telle qu’elle sera chiffrée.

#### 8.2.2 Données associées (AAD) canoniques

L’AAD est un objet JSON sérialisé selon la RFC 8785 (JSON Canonicalization Scheme), puis encodé en UTF-8. Les octets ainsi obtenus sont transmis tels quels au serveur, stockés sans modification et renvoyés au lecteur ; le lecteur les utilise directement comme AAD et ne les reconstruit pas.

Champs, tous obligatoires, aucun autre n’étant admis :

| Champ | Type | Contenu |
|---|---|---|
| `v` | entier | version du protocole (`1`) |
| `alg` | chaîne | `"A256GCM"` |
| `read_once` | booléen | mode lecture unique |
| `expiration` | chaîne | code de durée demandé, parmi l’ensemble fermé `"5m"`, `"1h"`, `"1d"`, `"7d"`, `"30d"`, `"never"` |
| `kdf` | objet ou `null` | `{"alg":"argon2id13","m":<KiB>,"t":<passes>,"p":1,"salt":"<base64url>"}` |
| `access_pk` | chaîne | clé publique d’accès Ed25519, base64url |
| `consume_pk` | chaîne ou `null` | clé publique de consommation Ed25519, base64url, `null` hors lecture unique |

Règles de canonicalisation et de validation, identiques en JavaScript, en PHP et dans la CLI :

- sérialisation RFC 8785 : clés triées par unités de code UTF-16, à tous les niveaux d’imbrication, sans espace ;
- toutes les chaînes de l’AAD sont restreintes à l’ASCII imprimable (codes, base64url), ce qui élimine toute ambiguïté d’échappement ou de normalisation Unicode ;
- les nombres sont uniquement des entiers dans `[0, 2^53 − 1]` ; nombres flottants, exposants, `-0`, `NaN` et `Infinity` sont interdits ;
- les tableaux sont interdits dans l’AAD V1 ;
- les clés dupliquées, les clés inconnues, les clés manquantes et les types inattendus provoquent un rejet, côté serveur comme côté client ;
- le serveur vérifie que les octets reçus sont exactement la sérialisation canonique de l’objet décodé ; sinon la requête est rejetée ; cette comparaison octet à octet détecte aussi les clés dupliquées, que `json_decode` de PHP écrase silencieusement ;
- comme toutes les chaînes sont en ASCII imprimable et ne contiennent ni `"` ni `\`, la sérialisation canonique s’obtient en PHP par un tri récursif des clés (`ksort` avec `SORT_STRING`) suivi de `json_encode` avec `JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR` ; une bibliothèque RFC 8785 complète n’est pas requise ;
- le serveur vérifie la cohérence de l’AAD avec la requête et la configuration : longueurs exactes (`access_pk` et `consume_pk` de 32 octets, `salt` de 16 octets, nonce de 12 octets) ; durée autorisée ; `read_once` permis par `allow_read_once` ; `read_once = true` si et seulement si `consume_pk` est non nul ; `kdf` non nul seulement si `allow_passphrase = true` ; paramètres KDF dans les bornes (§8.3) ; `alg` et `v` supportés ;
- l’identifiant et les champs gérés par le serveur (`created_at`, `expires_at`, état, compteurs, réservation) sont hors AAD ; `created_at` est l’heure de réception par le serveur et `expires_at = created_at + durée` ; ces valeurs ne sont pas authentifiées par le client et cette limite est documentée dans le modèle de menace ;
- le lecteur vérifie que `access_pk` présente dans l’AAD est égale à celle qu’il dérive de `K_url`, dès la réponse de `status` puis à `open` ; il exige en outre que l’AAD reçue d’`open` soit identique octet pour octet à celle reçue de `status`, afin qu’un serveur malveillant ne puisse pas contourner l’écran « Révéler » ou la vérification locale de la phrase secrète ; tout écart est traité comme un échec d’intégrité.

Toute modification de l’AAD par le serveur ou le stockage, y compris la désactivation de la lecture unique ou le changement des paramètres KDF, provoque un échec du déchiffrement ; la substitution par le contenu d’un lien de `K_url` différente est détectée, car sa `K_enc` et son `access_pk` diffèrent de celles dérivées du lien. L’AAD n’authentifiant ni l’identifiant ni les dates, deux contenus créés avec la même `K_url` seraient indiscernables (cas légitime d’un contenu orphelin d’idempotence, sans danger puisque son identifiant n’a jamais été communiqué), et `expires_at` n’est pas authentifié (§7.3).

#### 8.2.3 Encodages et limites de taille

- dans le JSON de l’API, toutes les valeurs binaires (`ciphertext`, `nonce`, `salt`, clés publiques, signatures, hash) sont encodées en base64url sans padding (RFC 4648 §5), sous forme **canonique** : les bits de remplissage du dernier caractère sont nuls, et toute chaîne non canonique, contenant des caractères hors alphabet ou du padding est rejetée par le frontend, le backend et la CLI ; la même règle s’applique à l’identifiant, à la clé du fragment et au jeton de suppression dans les URLs ; les vecteurs de test incluent des chaînes non canoniques à rejeter ; l’AAD est transmise comme chaîne base64url de ses octets ;
- les tailles sont définies à quatre niveaux distincts :

| Niveau | Option | Défaut | Contrôlé par |
|---|---|---|---|
| enveloppe sérialisée (§8.2.1), octets UTF-8 | `paste.max_envelope_bytes` | 1 048 576 (1 Mio) | client (jauge et blocage) ; le serveur ne voit pas le clair |
| ciphertext binaire décodé, tag inclus | `paste.max_ciphertext_bytes` | `max_envelope_bytes + 16` = 1 048 592 | serveur, après décodage |
| AAD canonique, octets | `paste.max_metadata_bytes` | 4 096 | serveur |
| corps HTTP total | `http.max_request_bytes` | 1 441 792 (1 408 Kio, base64url ≈ 4/3 inclus) | serveur web et application, avant parsing |

`paste.max_ciphertext_bytes` n’est pas réglable indépendamment : il est toujours égal à `max_envelope_bytes + 16` (tag GCM), ce qui garantit qu’une enveloppe acceptée par le client est acceptée par le serveur.

- la cohérence `max_request_bytes ≥ ⌈max_ciphertext_bytes × 4/3⌉ + max_metadata_bytes × 4/3 + 16 Kio` est vérifiée au démarrage ;
- le reverse proxy (`client_max_body_size`, `LimitRequestBody`) doit être aligné sur `http.max_request_bytes` ;
- le corps est refusé dès que `Content-Length` ou le flux lu dépasse la limite, avant tout décodage JSON.

Le fragment URL n’est pas envoyé dans les requêtes HTTP classiques. Il doit toutefois être traité comme un secret : ne pas le copier dans les logs, les analytics, les erreurs, les traces ou les URLs externes.

### 8.3 Phrase secrète

La phrase secrète ne doit pas être stockée en clair.

Si elle est utilisée en complément de la clé URL :

- utiliser Argon2id comme KDF obligatoire pour le mode phrase secrète ;
- intégrer une implémentation Argon2id auditée (hash-wasm en V1, exécutée dans le worker dédié), versionnée et incluse dans le SBOM frontend ; elle peut être fournie en WebAssembly ou par un autre module local validé ;
- autoriser au besoin uniquement `wasm-unsafe-eval` dans la CSP, jamais `unsafe-eval` ;
- désactiver le mode phrase secrète à la création si l’environnement ne supporte pas l’implémentation validée ; à la lecture d’un contenu protégé par phrase secrète, afficher un message explicite « Votre navigateur ne permet pas d’ouvrir ce contenu protégé par phrase secrète », avec les navigateurs supportés, sans contacter `open` ni réserver une lecture unique ;
- ne pas basculer silencieusement vers PBKDF2 ou une autre KDF moins résistante ;
- utiliser un sel aléatoire de 16 octets exactement (contrainte `SODIUM_CRYPTO_PWHASH_SALTBYTES` de libsodium) ;
- dériver `K_pass = Argon2id v1.3(passphrase, salt, m, t, p = 1, L = 32)`, la phrase secrète étant encodée en UTF-8 après normalisation Unicode NFC (`String.prototype.normalize('NFC')` côté navigateur, `Normalizer::normalize($p, Normalizer::FORM_C)` de `ext-intl` côté CLI) ;
- fixer le parallélisme à `p = 1` : c’est la seule valeur produite par `sodium_crypto_pwhash()` côté CLI, et l’implémentation navigateur doit s’y aligner ;
- exprimer `m` en Kio ; côté libsodium, `memlimit = m × 1024` octets et `opslimit = t` ;
- valider côté serveur et côté lecteur des bornes minimales et maximales, fixées par le protocole v1 et non configurables (`19 456 ≤ m ≤ 262 144` Kio et `2 ≤ t ≤ 10`) afin d’empêcher à la fois des paramètres trop faibles et un déni de service sur l’appareil du lecteur ;
- dériver `K_enc` et `K_consume_seed` selon le schéma de §8.2 ;
- stocker dans l’AAD (§8.2.2) le sel, l’identifiant de la KDF et ses paramètres, car ils sont nécessaires avant le déchiffrement ; ne jamais stocker la phrase secrète ni `K_pass` ;
- ne jamais remplacer la clé aléatoire par une simple clé dérivée d’un mot de passe faible ;
- afficher un avertissement si la phrase secrète est manifestement faible.

Les paramètres Argon2id par défaut sont `m = 65 536` Kio (64 Mio) et `t = 3`, avec `p = 1` ; ils sont confirmés ou ajustés par la calibration de Phase 0 sur les appareils mobiles supportés et documentés dans le protocole. Toute modification de ces paramètres doit incrémenter la version du format ou rester compatible avec les paramètres enregistrés dans le payload.

### 8.4 Intégrité et versionnement

Le format doit contenir au minimum :

- une version de protocole ;
- l’algorithme ;
- le nonce ;
- le ciphertext ;
- l’identifiant de KDF, le sel et ses paramètres lorsqu’une phrase secrète est utilisée ;
- les clés publiques d’accès et de consommation ;
- l’AAD canonique (§8.2.2), qui porte la version, l’algorithme et les paramètres ci-dessus.

Toute modification du payload doit provoquer une erreur de déchiffrement explicite.

Pour les preuves d’accès et de consommation, l’algorithme V1 est Ed25519 (RFC 8032, signature pure, sans pré-hachage). Le serveur vérifie les signatures avec `sodium_crypto_sign_verify_detached()` ; `ext-sodium` est donc requise par le backend comme par la CLI. Côté navigateur, Web Crypto n’importe pas une graine brute : la graine est encapsulée dans une structure PKCS#8 (préfixe DER fixe de 16 octets `302e020100300506032b657004220420` suivi de la graine), dont l’encodage est couvert par les vecteurs de test. La preuve d’accès en Ed25519 étant requise pour **toute** lecture, et les paires Ed25519 étant générées à chaque création, le frontend doit livrer obligatoirement un module Ed25519 local, audité, versionné, inclus dans le SBOM et conforme aux vecteurs de test. Il utilise Web Crypto lorsque l’implémentation du navigateur est disponible et conforme aux vecteurs, et ce module local (@noble/ed25519 en V1) dans le cas contraire ; le choix est fait au chargement, sans action de l’utilisateur. Aucun mécanisme plus faible ne remplace Ed25519. Le module local est en **JavaScript pur**, afin de ne jamais dépendre de `'wasm-unsafe-eval'` ; il ne fait l’objet d’aucun chargement distant. Avec Web Crypto, la clé privée importée en PKCS#8 doit être déclarée `extractable: true` pour en dériver la clé publique (export JWK, champ `x`) ; elle n’est jamais exportée ailleurs et ses références sont supprimées après usage. Ed25519 étant déterministe, la conformité est vérifiée sur les clés publiques, sur les signatures des vecteurs et sur leur vérification croisée. Le client conserve la signature de consommation pour un éventuel renvoi idempotent (§6.3.1) au lieu de signer à nouveau.

### 8.5 Format d’URL

Le format V1 doit être :

```text
https://<instance>/p/<id>#<key>
```

Le lien de gestion et de suppression doit être distinct :

```text
https://<instance>/manage/<id>#<deletion-token>
```

La page `/manage/<id>` :

- n’affiche jamais le contenu et ne peut pas le déchiffrer, puisqu’elle ne connaît pas `K_url` ;
- n’affiche aucune information sur l’existence ou l’état du contenu avant l’action de suppression ;
- propose une seule action, « Supprimer définitivement », avec une confirmation explicite rappelant l’irréversibilité ;
- affiche ensuite un résultat unique, « Ce contenu n’est plus disponible », que la suppression ait eu lieu, que le contenu ait déjà expiré ou que le jeton soit invalide ;
- signale un lien de gestion tronqué (jeton absent ou de longueur invalide) sans contacter le serveur.

Règles :

- `<id>` est l’identifiant public de 192 bits (24 octets) attribué par le serveur (64 bits d’empreinte de `access_pk`, 64 bits d’empreinte du jeton de suppression et 64 bits aléatoires, §8.2), encodé en base64url canonique sans padding (32 caractères) ;
- `<key>` est la clé URL encodée en base64url sans padding ;
- `<deletion-token>` est un jeton aléatoire généré par le client et encodé en base64url sans padding ;
- la phrase secrète n’est jamais incluse dans l’URL ;
- le serveur ne reçoit jamais le fragment après `#` ;
- lors de la création, le serveur ne reçoit et ne stocke que le hash du jeton de suppression ;
- le frontend transmet le jeton de suppression à l’API uniquement dans un en-tête dédié, jamais dans le chemin, la query string, un log ou un message d’erreur ;
- le lien de gestion ne doit jamais être inclus dans un QR code ou une action de partage native ;
- le frontend ne doit jamais envoyer l’URL complète à un service tiers ;
- l’URL complète ne doit pas être écrite dans les logs, caches, analytics ou messages d’erreur ;
- le format doit être documenté et couvert par des tests de compatibilité frontend, CLI et backend.

### 8.6 Prévisualisation et robots

Les URLs de lecture doivent limiter les prévisualisations automatiques :

- réponse HTML ne contenant pas le texte en clair côté serveur ;
- balises `noindex`, `nofollow` et `noarchive` ;
- politique `Referrer-Policy: no-referrer` ;
- pas de ressources tierces sur la page de lecture ;
- pas de partage automatique vers des services externes.

## 9. Architecture cible

### 9.1 Composants

- **Frontend web :** HTML, CSS, système de thèmes et JavaScript statique servi par l’instance ; chiffrement, déchiffrement et rendu local.
- **Backend/API Symfony :** endpoints de création, challenge d’accès, ouverture, statut, consommation, suppression et santé du service (§10).
- **Stockage de fichiers :** stockage de référence V1 pour les payloads chiffrés et leurs métadonnées minimales, avec verrouillage exclusif et écritures atomiques pour la lecture unique.
- **Nettoyage d’expiration :** commande Symfony dédiée lancée par Cron ou systemd, complétée par un nettoyage opportuniste sur les requêtes.
- **CLI PHP :** client indépendant utilisant le même format de protocole.

### 9.2 Contraintes technologiques

Le projet doit obligatoirement utiliser PHP côté serveur.

- PHP 8.3 ou version stable supérieure retenue en Phase 0 ;
- typage strict activé dans le code PHP ;
- dépendances gérées exclusivement avec Composer ;
- Symfony comme framework PHP obligatoire, en mode micro-kernel (FrameworkBundle minimal), avec les composants utilisés de manière ciblée : HttpFoundation, Routing, Validator, Console, RateLimiter et Twig pour les seuls gabarits HTML de l’interface, qui ne reçoivent jamais de contenu utilisateur ; le composant Lock (et notamment `FlockStore`, qui crée des fichiers de verrou qu’il ne supprime jamais) n’est pas utilisé : les contenus sont verrouillés par `flock()` direct sur leur `state.lock` (§9.4.1) ;
- rate limiting stocké sur fichiers (adaptateur de cache filesystem dans un répertoire dédié, hors de `var/cache`), avec des clés construites à partir d’un HMAC de l’adresse IP du client ; derrière un reverse proxy, cette adresse n’est lue dans `X-Forwarded-For` ou `Forwarded` que si la requête provient d’un proxy déclaré dans `http.trusted_proxies` (mécanisme `trusted_proxies` de Symfony), et l’adresse de connexion est utilisée sinon ; une instance derrière un proxy sans `trusted_proxies` configuré est signalée par `app:boot` ; les adresses IPv4 mappées en IPv6 (`::ffff:0:0/96`) sont normalisées en IPv4 ; les adresses IPv6 natives étant d’abord agrégées par préfixe (`/64` par défaut, préfixe configurable par `http.ratelimit_ipv6_prefix`, entre `/48` et `/64`) afin qu’un attaquant disposant d’un bloc entier ne contourne pas la limitation, sous une clé quotidienne `HKDF-SHA-256(QUIETLINK_APP_SECRET, salt = "sp-proto/v1/hkdf", info = "sp-proto/v1/server/ratelimit/<AAAA-MM-JJ>")`, et une durée de vie égale à la fenêtre de limitation ; la date `<AAAA-MM-JJ>` est exprimée en UTC ; pour qu’un changement de jour ne remette pas les compteurs à zéro, une requête est comptée sous la clé du jour courant et évaluée sur la somme des compteurs du jour courant et du jour précédent pour les fenêtres qui chevauchent minuit ; les mises à jour des compteurs sont protégées par `flock()` sur un ensemble fixe de 256 fichiers de verrou créés par `app:boot` et répartis selon le premier octet du HMAC, de sorte qu’aucun fichier de verrou n’est créé par requête ; aucune adresse IP en clair n’est écrite sur le disque ; la contention de verrous sous PHP-FPM est mesurée lors des tests de charge (Phase 3) ;
- PSR-12 pour le style de code ;
- langue de la documentation technique : les commentaires, docblocks PHPDoc et messages d’exception internes du code PHP (backend, CLI, tests), les commentaires du code frontend, tous les fichiers README, `SECURITY.md`, `CODE_OF_CONDUCT.md`, le guide de contribution, le changelog, la spécification OpenAPI et la documentation du protocole sont rédigés **en anglais** ; seuls le présent cahier des charges et les catalogues de traduction de l’interface (`fr` et autres langues) sont dans d’autres langues ;
- PSR-4 pour l’autoloading ;
- PSR-3 pour les logs ;
- validation stricte des entrées et sorties JSON ;
- stockage de fichiers local comme stockage de référence V1, avec format de répertoire, permissions, verrouillage et écritures atomiques documentés ;
- aucun secret dans le code source ou les images Docker ;
- tests PHPUnit obligatoires pour le backend ;
- PHPStan ou outil équivalent en analyse statique ;
- TDD obligatoire selon le cycle Red → Green → Refactor ;
- PHP-FPM avec Nginx ou Apache en production ;
- image Docker dédiée au runtime PHP, exécutée obligatoirement avec un utilisateur non root ;
- avec un filesystem racine en lecture seule, le cache Symfony (`var/cache/prod`) est préchauffé lors du build de l’image et monté en lecture seule ; seuls sont accessibles en écriture : le volume de stockage (contenus, idempotence, rate limiting, état), le volume des assets générés et un `tmpfs` pour `/tmp`.

La CLI doit utiliser exclusivement des extensions natives pour les primitives cryptographiques :

- AES-256-GCM : `ext-openssl` (`openssl_encrypt`/`openssl_decrypt` avec `aes-256-gcm`, tag de 16 octets) ; les fonctions `sodium_crypto_aead_aes256gcm_*` ne doivent pas être utilisées, car elles exigent un support matériel AES-NI et sont indisponibles sur une partie des plateformes, notamment ARM ;
- Argon2id : `ext-sodium` (`sodium_crypto_pwhash` avec `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`) ;
- Ed25519 : `ext-sodium` (`sodium_crypto_sign_seed_keypair`, `sodium_crypto_sign_detached`, `sodium_crypto_sign_verify_detached`) ;
- HKDF-SHA-256, HMAC-SHA-256 et SHA-256 : `hash_hkdf`, `hash_hmac` et `hash` natifs ;
- normalisation Unicode NFC de la phrase secrète : `ext-intl` (`Normalizer`), requise par la CLI ;
- aléa : `random_bytes`.

La CLI et le backend doivent refuser de démarrer si l’une de ces extensions ou fonctions est indisponible ; aucune primitive cryptographique ne doit être réimplémentée en PHP pur.

Le frontend peut utiliser JavaScript pour le chiffrement dans le navigateur, mais le serveur ne doit pas utiliser JavaScript côté backend pour traiter les contenus.

### 9.3 Séparation des responsabilités

Le serveur ne doit pas recevoir :

- le texte en clair ;
- la clé de déchiffrement ;
- la phrase secrète en clair ;
- le contenu d’un aperçu ;
- des données analytiques contenant un lien complet.

### 9.4 Stockage

Le modèle de l’enregistrement de fichier doit limiter les champs à :

- identifiant attribué par le serveur (§8.2), non séquentiel ;
- payload chiffré et nonce ;
- AAD canonique, qui porte la version de protocole, les paramètres KDF publics et les clés publiques d’accès et de consommation (§8.2.2) ;
- date de création ;
- date d’expiration ;
- indicateur de lecture unique ;
- état (`available`, `reserved`, `consumed`, et l’état technique `deleted` écrit pendant une suppression, §9.4.1) et horodatage `terminal_at` du passage à `consumed` ou `deleted` ;
- hash de l’identifiant de réservation, challenge de consommation lié à cette réservation (stocké tel quel, §6.3.1), date d’expiration de la réservation et, après consommation, hash de la signature ayant consommé ;
- compteur d’ouvertures non confirmées (`unconfirmed_opens`) ;
- jeton de suppression stocké sous forme hachée ;
- version du format de stockage ;
- dans l’index d’idempotence séparé, un fichier écrit une seule fois par clé : hash de la clé d’idempotence, empreinte SHA-256 du corps de requête, identifiant attribué, `expires_at` et date limite de conservation.

L’identifiant comporte 64 bits aléatoires attribués par le serveur et deux empreintes de 64 bits (§8.2), ce qui empêche l’énumération.
Les transitions de lecture unique doivent être protégées par un verrou exclusif sur `state.lock`, une vérification de l’état sous verrou et une écriture temporaire suivie d’un renommage atomique. Aucun moteur SQL, NoSQL ou service de base de données externe ne doit être requis.

#### 9.4.1 Stockage de fichiers V1

Le répertoire de données est configurable dans `config.php` par `storage.data_dir` ; par défaut, il s’agit du dossier `datas/` à la racine du projet (un chemin relatif est résolu depuis la racine du projet). Il doit rester hors de la racine web `public/`. Les répertoires `storage.root_dir`, `storage.idempotency_dir`, `storage.ratelimit_dir` et `storage.state_dir` en dérivent par défaut (`datas/pastes`, `datas/idempotency`, `datas/ratelimit`, `datas/state`) et peuvent chacun être configurés séparément. En Docker, le système de fichiers racine étant en lecture seule, le volume de données est monté sur ce répertoire.

Chaque contenu doit être stocké dans un répertoire partitionné par préfixes de son identifiant afin d’éviter un trop grand nombre de fichiers dans un même répertoire :

```text
<racine du projet>/datas/          # storage.data_dir
├── pastes/
│   └── ab/
│       └── cd/
│           └── <id>/
│               ├── payload.bin     # immuable : nonce ‖ ciphertext
│               ├── meta.json       # immuable : AAD, dates, hash du jeton de suppression
│               ├── state.json      # mutable : état, réservation, compteur
│               └── state.lock
├── idempotency/
├── ratelimit/
└── state/              # storage.state_dir
    ├── usage.json      # compteurs de quotas (taille, nombre de contenus)
    ├── health.json     # seuils d’espace et d’inodes, horodaté, écrit par la purge et app:boot
    ├── boot.json       # marqueur d’amorçage (empreinte de la configuration validée)
    ├── usage.lock
    ├── purge.lock
    └── creating/       # marqueurs vides, nommés aléatoirement, des créations en cours

/var/lib/quietlink-generated/   # storage.generated_assets_dir, volume distinct
└── tokens.<hash>.css
```

Règles obligatoires :

- `payload.bin` et `meta.json` sont écrits une seule fois à la création et ne sont jamais modifiés ; les transitions d’état (réservation, libération, compteur, consommation) ne réécrivent que `state.json`, de petite taille, sans recopier le ciphertext ;
- aucun de ces fichiers ne contient de texte en clair, de clé de déchiffrement, de phrase secrète ou de jeton de suppression brut ;
- un contenu est considéré comme existant uniquement si son répertoire final contient les quatre fichiers `payload.bin` (sauf après consommation), `meta.json`, `state.json` et `state.lock` ; les fichiers temporaires d’état (`.state.json.tmp-<aléa>`) sont créés directement dans `<id>/` et renommés aussitôt ; la création écrit ces quatre fichiers dans un répertoire temporaire `.<id>.tmp-<aléa>/` situé dans le même répertoire parent, les synchronise, puis renomme atomiquement ce répertoire en `<id>/` ; sous Linux, `rename()` d’un répertoire échoue si la cible existe et n’est pas vide, mais réussit si elle est vide : la création vérifie donc l’absence de `<id>/` juste avant le renommage ; en cas de collision sur l’aléa de 64 bits (improbable), le serveur tire un nouvel aléa et recommence ; un identifiant n’est jamais réattribué tant que son répertoire existe, et ne peut l’être ensuite qu’avec une probabilité de 2^-64 (§8.2) ;
- lors de la consommation, `payload.bin` est supprimé sous verrou juste après l’écriture de l’état `consumed` ;
- toute suppression d’un contenu (suppression manuelle, purge d’un contenu expiré ou d’un contenu consommé depuis plus de 10 minutes) se fait en deux temps : sous verrou, `state.json` passe d’abord à l’état terminal `deleted` avec `terminal_at`, et `payload.bin` est supprimé ; les autres fichiers puis le répertoire sont ensuite supprimés, `state.lock` en dernier ; si un arrêt brutal intervient entre ces étapes, la purge suivante termine la suppression de tout contenu en état `deleted` ;
- tout processus qui obtient le verrou doit, **après l’obtention du verrou**, vérifier que le descripteur verrouillé désigne toujours `<id>/state.lock` (égalité du périphérique et de l’inode entre `fstat()` sur le descripteur et `stat()` sur le chemin), puis relire l’état ; il abandonne l’opération, avec la réponse générique d’indisponibilité, si l’inode diffère, si le répertoire n’existe plus ou si l’état est `deleted`, ainsi que si l’état est `consumed`, sauf pour le rejeu idempotent de `consume` (§6.3.1) ; un verrou obtenu sur un fichier déjà supprimé ne permet donc jamais de réserver, servir ou modifier un contenu ;
- un répertoire partiellement supprimé ne doit jamais rester vide sous le nom `<id>/` : la purge supprime les fichiers puis le répertoire dans la même opération sous verrou, et un répertoire `<id>/` sans `state.json` ou sans `state.lock` trouvé par la purge est traité comme supprimé et retiré par celle-ci (`app:boot` ne parcourt pas les contenus) ;
- `state.lock` est utilisé avec `flock()` en mode exclusif pour les transitions de lecture unique, de suppression et de réservation ;
- `state.lock` est créé uniquement avec le répertoire temporaire, lors de la création du contenu ; il est ensuite toujours ouvert **sans création** (mode `r+`, jamais `c`, `a`, `w` ni `x`) ; un fichier de verrou absent signifie que le contenu n’existe pas, et la requête reçoit la réponse générique d’indisponibilité ; aucun processus ne peut ainsi recréer un verrou dans un répertoire en cours de suppression ;
- toute modification de `state.json` est écrite dans un fichier temporaire du même répertoire, synchronisée avec `fsync()` (PHP ≥ 8.1), puis appliquée par `rename()` atomique ;
- la synchronisation du répertoire parent après renommage n’est pas exposée de façon portable par PHP : elle est réalisée lorsque le runtime le permet, et sa durabilité est sinon garantie par le choix d’un système de fichiers journalisé supporté ; la liste des systèmes de fichiers supportés (ext4 avec `data=ordered` ou `journal`, XFS) et le comportement en cas de coupure électrique sont documentés ;
- au démarrage, l’application vérifie que `storage.root_dir` est sur un système de fichiers local de la liste supportée (par exemple via `/proc/mounts` sous Linux) et refuse de démarrer sinon, sauf dérogation explicite `storage.allow_unsupported_fs` journalisée en avertissement ;
- les répertoires et fichiers appartiennent à un compte système dédié au processus PHP et ne sont pas accessibles directement par le serveur web statique ;
- le répertoire racine de stockage doit être hors de `public/`, interdit à l’indexation et monté avec les permissions minimales ;
- le stockage V1 doit reposer sur un système de fichiers local supportant correctement `flock()` et `rename()` atomique ; NFS, SMB et tout stockage réseau sans garanties équivalentes sont hors support V1 ;
- le déploiement V1 supporte une instance applicative et un volume de stockage local ; la réplication horizontale et le partage du stockage entre plusieurs instances sont hors périmètre V1 ;
- aucun fichier temporaire ne doit être créé dans un répertoire partagé ou exposé au web ;
- après un redémarrage, les fichiers temporaires orphelins ne peuvent être supprimés qu’après vérification de leur ancienneté et de l’absence de verrou actif ; un verrou `flock()` est libéré automatiquement à la fin du processus ;
- les fichiers supprimés sont retirés du namespace avec `unlink()` et synchronisation du répertoire lorsque possible ; la suppression physique irréversible sur SSD n’est pas promise ;
- l’index d’idempotence est lui aussi stocké sous forme de fichiers ne contenant ni texte en clair ni jeton de suppression brut ; sa durée de conservation est bornée indépendamment de l’expiration du contenu (§10) afin d’éviter toute accumulation et l’épuisement des inodes ;
- la commande de purge parcourt uniquement les répertoires attendus, refuse les liens symboliques sortants et reste idempotente ; elle s’exécute sous un verrou global exclusif non bloquant (`purge.lock`) : une exécution qui trouve le verrou déjà pris se termine immédiatement, ce qui empêche les exécutions concurrentes lorsque la planification est plus fréquente que la durée d’une purge ;
- les contenus `consumed` sont conservés au moins 10 minutes après `terminal_at`, sans payload, pour l’idempotence de `consume` (§6.3.1), puis supprimés par la purge ; une suppression manuelle d’un contenu `consumed` conserve l’état `consumed` jusqu’à cette échéance afin de ne pas casser l’idempotence de `consume` ;
- une création en cours est signalée par un fichier vide, nommé aléatoirement, dans `state/creating/`, écrit avant la réservation du quota et supprimé après la validation ou l’abandon de la création ; le recalcul horaire de `usage.json` n’est pas appliqué tant qu’un marqueur de moins d’une heure existe, afin de ne pas effacer une création encore absente du disque ; la purge supprime les marqueurs de plus d’une heure laissés par un arrêt brutal ; ces marqueurs ne contiennent aucune donnée et ne modifient aucun format de fichier existant ;
- `usage.json` est protégé par un verrou dédié (`usage.lock`, créé par `app:boot`) ; le contrôle de quota et la réservation de la taille et d’un contenu se font sous ce même verrou, de sorte que des créations concurrentes ne peuvent pas dépasser les quotas ; il est mis à jour à chaque création (ajout de la taille du ciphertext et d’un contenu), à chaque suppression d’un contenu perdant ou en échec d’idempotence (retrait), et à chaque suppression de `payload.bin` (consommation, suppression, expiration : retrait de la taille) ; le nombre de contenus n’est décrémenté qu’à la suppression du répertoire ;
- les sauvegardes portent sur ce stockage de fichiers et doivent conserver les permissions, la cohérence des renommages et la confidentialité des ciphertexts ; une copie `tar` d’un répertoire actif n’est pas considérée comme une sauvegarde cohérente ;
- une sauvegarde doit être réalisée depuis un snapshot cohérent du système de fichiers ou après arrêt/mise en lecture seule du service ; une copie en direct d’un répertoire actif sans garantie de cohérence est interdite ; la restauration doit être testée régulièrement ;
- en conteneur, le filesystem racine doit rester en lecture seule ; seuls le volume de stockage de fichiers et les répertoires temporaires explicitement nécessaires peuvent être montés en écriture.

Le stockage de fichiers est la seule forme de persistance obligatoire en V1. Aucun serveur PostgreSQL, MySQL, SQLite, Redis, Valkey ou autre moteur de base de données ne doit être installé, configuré ou nécessaire au fonctionnement.

### 9.5 Fichier de configuration de l’instance

L’instance doit être configurable avec un fichier PHP dédié, sans modifier le code applicatif.

Structure recommandée :

```text
config/
├── config.php.example
├── config.php
└── config.local.php.example
```

Amorçage : PHP-FPM n’offrant pas de point d’exécution au démarrage de l’application, toute mention « au démarrage » dans ce document désigne l’exécution de la commande obligatoire `php bin/console app:boot`. Cette commande valide la configuration et les règles de cohérence ci-dessous, vérifie les extensions requises, les chemins, les permissions et le système de fichiers, mesure l’espace et les inodes libres, crée les fichiers de verrou du rate limiting et `usage.lock`, vérifie que `link()` et `rename()` fonctionnent sur les volumes de stockage (certains montages réseau, FUSE ou de développement ne les supportent pas) et génère le CSS des tokens de thème ; en cas d’échec, elle se termine avec un code non nul et l’entrypoint Docker comme l’unité systemd (`ExecStartPre`) refusent de démarrer PHP-FPM. En installation systemd, QuietLink dispose d’une instance PHP-FPM et d’une unité dédiées (pas de greffe sur l’unité `php-fpm` partagée de l’hôte), et `app:boot` s’exécute avec le même compte système que les workers (`User=` et `Group=` de l’unité), afin que les fichiers créés leur restent accessibles. Un rechargement (`systemctl reload`) exécute `ExecReload=` : `app:boot`, puis, seulement en cas de succès, l’envoi de `USR2` au processus maître de PHP-FPM ; en conteneur, l’entrypoint fournit la même séquence. Avec OPcache, `opcache.validate_timestamps` est désactivé en production et le rechargement de PHP-FPM réinitialise le cache, ce qui évite une fenêtre où la configuration chargée diffère du marqueur. En cas de succès, elle écrit un marqueur d’amorçage (`state/boot.json`) contenant l’empreinte de la configuration validée ; à chaque requête, l’application compare cette empreinte à celle de la configuration chargée et répond `503` générique si elles diffèrent ou si le marqueur est absent.

`config/config.php` et `config/config.local.php` sont lus à l’exécution (avec OPcache) et ne sont jamais compilés dans le conteneur Symfony préchauffé ; une modification de configuration suivie de l’exécution de `app:boot` et d’un rechargement de PHP-FPM est donc toujours prise en compte.

Le fichier `config.php` doit retourner une configuration typée et documentée. Un fichier `config.local.php` peut surcharger les valeurs propres à une installation et doit être exclu du contrôle de version.

Options configurables en V1 :

- nom et URL publique de l’instance ;
- thème actif et fichier de tokens visuels personnalisé ;
- langues activées ; l’anglais (`en`) reste le fallback fixe de la V1 et n’est pas configurable ;
- durée d’expiration par défaut ;
- durées d’expiration autorisées ;
- autorisation ou non de l’option « jamais » ;
- activation de la lecture unique (`allow_read_once`) ;
- durée d’une réservation de lecture unique (`read_once_reservation_ttl`), 60 secondes par défaut, comprise entre 30 et 300 secondes ;
- activation des phrases secrètes ;
- taille maximale de l’enveloppe sérialisée (`max_envelope_bytes`), dont découle la taille maximale du ciphertext ;
- taille maximale des métadonnées ;
- paramètres de rate limiting anti-abus ;
- répertoires du stockage de fichiers (contenus, idempotence, rate limiting, assets générés), situés hors de la racine web ;
- secret d’instance pour les challenges HMAC et les clés du rate limiting, fourni soit par la variable `QUIETLINK_APP_SECRET`, soit par un fichier désigné par `QUIETLINK_APP_SECRET_FILE` (convention adaptée aux Docker Secrets, montés sous forme de fichiers) ; sous PHP-FPM, dont l’option par défaut `clear_env = yes` masque l’environnement aux workers, la variable est transmise explicitement dans la configuration du pool (`env[QUIETLINK_APP_SECRET_FILE] = …` ou `env[QUIETLINK_APP_SECRET] = $QUIETLINK_APP_SECRET`) ; `app:boot`, qui s’exécute en CLI avant PHP-FPM, vérifie statiquement que la configuration du pool transmet la variable et inscrit dans le marqueur d’amorçage une empreinte non réversible du secret (`HMAC-SHA-256` de la chaîne `"sp-proto/v1/server/secret-check"` sous la clé dérivée du secret) ; à chaque requête, un worker dont le secret est absent ou dont l’empreinte diffère répond `503` générique, et `/healthz` signale l’anomalie, sans jamais afficher le secret ;
- seuil d’ouvertures non confirmées avant destruction d’une lecture unique (`max_unconfirmed_opens`, défaut 3, compris entre 1 et 10) ;
- durée maximale de conservation globale (`max_retention`) ;
- quotas de stockage : taille totale, nombre de contenus, espace et inodes libres minimaux ;
- durée maximale de conservation de l’idempotence (`idempotency_max_ttl`, défaut 24 heures) ;
- taille maximale du corps HTTP ;
- niveau et durée de conservation des logs ;
- activation des métriques agrégées ;
- configuration CORS, désactivée par défaut ;
- options d’interface : mode sombre, templates disponibles, QR code (`ui.enable_qr_code`, défaut `true`), impression (`ui.allow_print`, défaut `false`), export local (`ui.allow_export`, défaut `false`), manifest (`ui.enable_manifest`, défaut `false`).

Les contenus expirés ne sont jamais servis ni conservés au-delà de leur expiration : ils sont refusés dès l’échéance et ils sont entièrement supprimés au plus tard par la purge suivante (§9.7). Seuls les contenus consommés subsistent, sans payload ni ciphertext, pendant 10 minutes, pour l’idempotence de la consommation.

Exemple minimal :

```php
<?php

declare(strict_types=1);

return [
    'app' => [
        'public_url' => 'https://quietlink.example.test',
        'enabled_locales' => ['en', 'fr'],
    ],
    'theme' => [
        'name' => 'default',
        'custom_tokens_file' => null,
    ],
    'storage' => [
        'driver' => 'filesystem',
        // Relatif à la racine du projet ; les quatre répertoires suivants en dérivent par défaut.
        'data_dir' => 'datas',
        'root_dir' => null,          // datas/pastes
        'idempotency_dir' => null,   // datas/idempotency
        'ratelimit_dir' => null,     // datas/ratelimit
        'state_dir' => null,         // datas/state
        'generated_assets_dir' => '/var/lib/quietlink-generated',
        'max_total_bytes' => 10737418240,
        'max_items' => 100000,
        'min_free_bytes' => 1073741824,
        'min_free_inodes_percent' => 10,
        'allow_unsupported_fs' => false,
    ],
    'paste' => [
        'default_expiration' => '1d',
        'allowed_expirations' => ['5m', '1h', '1d', '7d', '30d'],
        'allow_forever' => false,
        'allow_read_once' => true,
        'allow_passphrase' => true,
        'max_envelope_bytes' => 1048576,
        'max_metadata_bytes' => 4096,
        'max_retention' => '30d',
        'max_unconfirmed_opens' => 3,
        'read_once_reservation_ttl' => 60,
        'idempotency_max_ttl' => '24h',
    ],
    'http' => [
        'max_request_bytes' => 1441792,
        'ratelimit_ipv6_prefix' => 64,
        'trusted_proxies' => [],
    ],
    'ui' => [
        'enable_qr_code' => true,
        'allow_print' => false,
        'allow_export' => false,
        'enable_manifest' => false,
    ],
];
```

Règles de sécurité de la configuration :

- le fichier de configuration ne doit jamais être servi par le serveur web ;
- les secrets et tokens ne doivent pas être écrits dans `config.php` ;
- les secrets doivent être fournis par variables d’environnement, Docker secrets ou un mécanisme équivalent ;
- `config.php.example` ne doit contenir aucun secret réel ;
- la configuration doit être validée au démarrage ;
- une configuration invalide doit empêcher le démarrage ou désactiver l’option concernée de manière sûre ;
- les fichiers de configuration doivent être lisibles uniquement par l’utilisateur du processus PHP ;
- `data_dir` vaut `datas` par défaut (racine du projet) ; un chemin relatif est résolu depuis la racine du projet ; `root_dir`, `idempotency_dir`, `ratelimit_dir` et `state_dir` valent par défaut `<data_dir>/pastes`, `<data_dir>/idempotency`, `<data_dir>/ratelimit` et `<data_dir>/state` ; `app:boot` refuse un répertoire de données situé dans `public/` ;
- les répertoires `data_dir`, `root_dir`, `idempotency_dir`, `ratelimit_dir`, `state_dir` et `generated_assets_dir` doivent être hors de la racine web ; `generated_assets_dir` est sur un volume distinct, seul monté dans le conteneur du serveur web, qui n’a jamais accès aux autres répertoires ; appartenir au compte système de l’application et ne pas traverser de lien symbolique sortant ;
- les chemins de stockage doivent être validés au démarrage et leur création automatique doit appliquer des permissions restrictives ;
- le chargement de fichiers arbitraires par une valeur de configuration doit être interdit ; seuls des fichiers de tokens situés dans le répertoire autorisé `config/themes/`, sans chemin absolu, sans `..` et sans lien symbolique sortant de ce répertoire, peuvent être chargés ;
- toute modification de configuration doit nécessiter un redémarrage ou un rechargement explicite du service ;
- la configuration effective doit pouvoir être vérifiée par une commande CLI (`php bin/console app:config:check`) sans révéler de secrets.

Règles de cohérence vérifiées au démarrage (toute violation empêche le démarrage) :

- `"never"` ne figure jamais dans `allowed_expirations` : l’option « jamais » est proposée uniquement par `allow_forever = true`, qui exige `max_retention = null` ;
- `default_expiration` et `allowed_expirations` ne peuvent contenir que les codes de l’ensemble fermé de l’AAD (§8.2.2) : `5m`, `1h`, `1d`, `7d`, `30d` (`never` étant géré par `allow_forever`) ; `max_retention` et `idempotency_max_ttl` utilisent le format `<entier><unité>` avec l’unité `m`, `h` ou `d` ;
- `max_unconfirmed_opens` est compris entre 1 et 10 ;
- chaque durée de `allowed_expirations` est inférieure ou égale à `max_retention` lorsque celle-ci est définie ;
- `default_expiration` appartient à `allowed_expirations` ;
- `read_once_reservation_ttl` est compris entre 30 et 300 secondes ;
- `idempotency_max_ttl` est compris entre 1 heure et 7 jours ;
- `http.max_request_bytes` respecte la formule de cohérence de §8.2.3 ;
- `QUIETLINK_APP_SECRET` (ou le contenu du fichier `QUIETLINK_APP_SECRET_FILE`) est présent, encodé en base64 standard (RFC 4648 §4), et décode vers au moins 32 octets ; ce sont **les octets décodés** qui servent d’IKM aux dérivations HKDF (`K_challenge`, clés du rate limiting) ; il est généré par `php bin/console app:secret:generate` (32 octets issus de `random_bytes`).

Priorité des sources de configuration :

1. valeurs par défaut versionnées dans l’application ;
2. `config/config.php` (lu à l’exécution) ;
3. `config/config.local.php` si présent ;
4. variables d’environnement et Docker Secrets uniquement pour les secrets et paramètres d’exécution explicitement autorisés.

Une variable d’environnement ne doit pas pouvoir désactiver silencieusement une protection de sécurité sans avertissement explicite et validation au démarrage.

Aucun backoffice web ne doit être développé en V1. Il ne doit pas exister de compte administrateur, de tableau de bord ou d’endpoint d’administration accessible depuis l’application. Les opérations d’instance sont réalisées hors interface web, avec la configuration, le runtime PHP, Docker et la CLI.

### 9.6 Stratégie de cache

La gestion des caches doit améliorer les performances sans risquer de conserver ou de révéler des données sensibles.

#### Données qui ne doivent jamais être mises en cache

Les éléments suivants doivent utiliser `Cache-Control: no-store` et ne doivent être conservés dans aucun cache applicatif, reverse proxy ou navigateur :

- contenu chiffré retourné par les endpoints de lecture ;
- réponses de création, lecture et suppression ;
- texte en clair ;
- clé de déchiffrement ;
- phrase secrète ;
- jeton de suppression ;
- URL complète contenant un identifiant de contenu ;
- état d’une lecture unique ;
- erreurs contenant des informations liées à un contenu ;
- pages pouvant afficher ou manipuler un contenu sensible.

Les fragments URL ne doivent jamais être copiés dans un cache, un log, une métrique ou un en-tête `Referer`.

#### Caches autorisés

Les caches peuvent être utilisés pour :

- conteneur compilé Symfony ;
- routes et configuration validées ;
- traductions ;
- templates d’interface ;
- thèmes et tokens visuels ;
- fichiers JavaScript, CSS, polices locales et icônes versionnés ;
- OPcache PHP ;
- métadonnées techniques non sensibles.

Le cache Symfony ne doit jamais contenir de texte en clair, de clé, de phrase secrète, de jeton de suppression ou de payload déchiffré.

#### Assets statiques

- les assets doivent utiliser un nom versionné ou un hash de contenu ;
- les assets immuables peuvent utiliser `public, max-age` long et `immutable` ;
- les assets de thème et de traduction doivent être invalidés après modification ;
- aucune ressource statique ne doit être chargée depuis un CDN tiers ;
- un changement de configuration de thème doit prendre effet après purge ou nouvelle version du cache ;
- les mises à jour doivent éviter de servir un mélange incohérent de versions frontend.

#### OPcache et cache de configuration

En production :

- OPcache doit être activé ;
- les options de validation des timestamps doivent être définies explicitement ;
- un redémarrage PHP-FPM ou une purge contrôlée doit accompagner les releases ;
- la configuration du framework Symfony et les routes sont compilées au build ; la configuration d’instance (`config.php`, `config.local.php`) n’est jamais compilée et est lue à l’exécution (§9.5) ;
- une configuration invalide ne doit jamais être mise en cache comme configuration valide.

#### Reverse proxy et navigateur

- les routes `/api/v1/pastes` doivent être exclues de tout cache partagé ;
- les pages de création et de lecture doivent être privées et non cacheables ;
- les headers anti-cache doivent être testés derrière Nginx, Apache et reverse proxy ;
- aucun Service Worker ne doit mettre en cache les pages ou réponses sensibles ;
- le navigateur ne doit pas conserver automatiquement le contenu en clair dans le cache applicatif.

#### Invalidation et tests

Le projet doit fournir :

- une commande CLI de purge contrôlée des caches non sensibles ;
- une procédure de purge lors d’un changement de thème, de traduction ou de configuration ;
- des clés de cache séparées par version d’application et instance ;
- des tests vérifiant qu’aucun payload ou secret n’entre dans un cache ;
- des tests des headers avec et sans reverse proxy ;
- une documentation de la durée de vie de chaque cache.

### 9.7 Nettoyage des contenus expirés

La V1 doit fournir la commande Symfony suivante :

```sh
php bin/console app:purge-expired
```

Cette commande doit être idempotente, limitée aux contenus expirés ou consommés, aux réservations échues, aux enregistrements d’idempotence échus et aux entrées de rate limiting expirées, et exécutable par Cron ou systemd (fréquence recommandée : toutes les minutes, afin que les réservations échues soient libérées rapidement même sans nouvelle requête). Elle s’exécute sous le verrou global `purge.lock` (§9.4.1), applique ses suppressions à `usage.json` par décréments sous verrou, recalcule entièrement `usage.json` au plus une fois par heure (en appliquant sous verrou l’écart constaté, sans écraser les créations concurrentes), met à jour `health.json` (espace et inodes libres, horodatage), supprime les contenus expirés, termine la suppression des contenus en état `deleted` et supprime les contenus `consumed` depuis plus de 10 minutes. Elle refuse de s’exécuter si le marqueur d’amorçage `state/boot.json` est absent ou ne correspond pas à la configuration chargée, afin qu’une configuration non validée ne puisse jamais piloter des suppressions. Le nettoyage opportuniste sur les requêtes reste un filet de sécurité, mais ne remplace pas la tâche planifiée.

Symfony Messenger ou Scheduler pourra être étudié ultérieurement, mais n’est pas requis pour la V1.

## 10. API V1

Le contrat de l’API est décrit par une spécification OpenAPI 3.1 versionnée avec le code ; elle constitue un livrable et sert de base aux tests de contrat. Toutes les valeurs binaires sont encodées en base64url sans padding (§8.2.3).

Politique de version : le préfixe `/api/v1` est stable pendant toute la durée de vie de la V1. Une modification incompatible impose un préfixe `/api/v2` ; l’ajout d’un champ optionnel en réponse n’est pas une modification incompatible. Une version d’API retirée est annoncée au moins une version mineure à l’avance dans le changelog.

### `POST /api/v1/pastes`

Crée un contenu chiffré.

Le serveur accepte uniquement un payload déjà chiffré et des métadonnées validées. Il ne doit exister aucun endpoint acceptant du texte en clair sous couvert de simplicité.

Corps : `aad` (octets canoniques, §8.2.2), `nonce`, `ciphertext`, `deletion_hash`. Aucun autre champ n’est accepté.

Réponse attendue :

- identifiant public attribué par le serveur (§8.2), le corps de la requête n’en contenant jamais ;
- `expires_at` au format ISO 8601 UTC, ou `null` pour un contenu « jamais » (possible uniquement si `allow_forever = true`, ce qui exclut `max_retention`) ;
- `server_time` au format ISO 8601 UTC, représentant l’heure d’autorité de l’instance au moment de la réponse ;
- informations nécessaires à la construction du lien côté client.

Le client génère le jeton de suppression et transmet uniquement son hash dans la requête de création. Le jeton brut n’est jamais reçu ni stocké par le serveur ; il reste disponible uniquement dans le contexte local ayant créé le contenu.

#### Idempotence

- La création exige un en-tête `Idempotency-Key` aléatoire de 128 bits encodé en base64url canonique ; une requête sans cet en-tête est rejetée (`400`).
- L’empreinte de requête est `SHA-256` du corps HTTP brut reçu.
- Pour une même clé et une même empreinte, une nouvelle tentative retourne le même identifiant et les mêmes métadonnées sans créer de second contenu, même si les quotas ou le rate limiting de création refuseraient désormais une nouvelle création. Une même clé utilisée avec une empreinte différente est rejetée (`422`).
- Une nouvelle tentative après timeout ou erreur réseau doit réutiliser **exactement** le même corps, octet pour octet : même ciphertext, même nonce, même AAD et même hash du jeton de suppression. Le client ne doit jamais rechiffrer pour une nouvelle tentative avec la même clé d’idempotence ; s’il doit rechiffrer, il utilise une nouvelle clé d’idempotence et une nouvelle `K_url`.
- L’enregistrement d’idempotence est conservé pendant `min(expiration du contenu, idempotency_max_ttl)`, avec `idempotency_max_ttl` de 24 heures par défaut et plafonné à 7 jours. Il est supprimé par la purge. Pour un contenu « jamais » expirant, seule la borne `idempotency_max_ttl` s’applique ; aucun fichier d’idempotence n’est conservé indéfiniment.
- L’enregistrement d’idempotence n’a **aucun état intermédiaire** : il est écrit une seule fois, complet, après la création du contenu, et n’est jamais modifié ni réécrit. Traitement d’une création :
  1. validation syntaxique du corps et de l’AAD (format, encodages, longueurs, canonicalisation), indépendante de la configuration ;
  2. si un enregistrement existe pour cette clé : empreinte différente → `422` ; sinon, la réponse enregistrée (identifiant, `expires_at`) est renvoyée telle quelle (`200`), sans aucune écriture, sans application des quotas ni du compteur de création, mais sous une limite de débit propre aux rejeux, par adresse IP, plus large que celle de la création ;
  3. sinon : contrôles dépendant de la configuration (durée autorisée, `allow_read_once`, `allow_passphrase`, bornes KDF), rate limiting de création, puis, sous le verrou de `usage.json`, contrôle des quotas et réservation de la taille et d’un contenu ; tirage d’un nouvel aléa `R` et création du contenu (répertoire temporaire puis renommage, §9.4.1), `meta.json` contenant le hash de la clé d’idempotence ;
  4. écriture de l’enregistrement dans un fichier temporaire créé dans `idempotency_dir` même (jamais dans `/tmp`, sur un autre système de fichiers), synchronisé par `fsync()`, puis publication atomique par `link()` vers son nom définitif, qui échoue si un enregistrement existe déjà, et suppression du fichier temporaire ; `link()` ne renvoyant que `false` en PHP, un échec est qualifié par `is_file()` sur le nom définitif : s’il existe, c’est une course (étape 5) ; sinon, c’est une erreur d’écriture, le contenu créé est supprimé, `usage.json` est décrémenté et la réponse est `503` ;
  5. si `link()` échoue parce qu’une requête concurrente avec la même clé a publié son enregistrement entre-temps, le contenu créé à l’étape 3, dont l’identifiant n’a jamais été communiqué, est supprimé et `usage.json` décrémenté ; l’enregistrement gagnant est relu : si son empreinte diffère, la réponse est `422`, sinon sa réponse est renvoyée ;
  6. si le processus s’arrête entre les étapes 3 et 4, aucun enregistrement n’existe : une nouvelle tentative crée un **nouveau** contenu sous un **nouvel** identifiant ; le contenu orphelin, dont le lien n’a jamais été communiqué, n’est accessible à personne ; la purge supprime tout contenu créé depuis plus de 15 minutes et depuis moins de `idempotency_max_ttl` dont aucun enregistrement d’idempotence ne désigne l’identifiant (hash de la clé lu dans `meta.json`), y compris pour un contenu « jamais » ;
- aucune reprise ne réutilise donc jamais un identifiant : un contenu consommé, supprimé ou expiré ne peut pas être recréé sous son lien par le mécanisme d’idempotence ;
- les créations concurrentes avec la même clé sont arbitrées par `link()`, sans verrou ni réécriture de l’enregistrement ;
- L’enregistrement d’idempotence ne contient ni texte en clair, ni jeton de suppression brut, ni ciphertext.

### `POST /api/v1/pastes/{id}/challenge`

Retourne un challenge d’accès sans état (§6.3.1). Le corps précise l’usage (`open` ou `status`). Le challenge est calculé sans aucun accès au stockage : la réponse est identique dans sa forme, que le contenu existe ou non. La page HTML `/p/<id>` peut fournir directement un challenge `open` et un challenge `status`, ce qui évite cet appel au premier chargement.

### `POST /api/v1/pastes/{id}/open`

Corps : le challenge reçu, `access_pk`, sa signature par la clé d’accès Ed25519 et, pour une lecture unique, l’identifiant de réservation de 128 bits généré par le client (§6.3.1).

Après vérification, retourne le ciphertext, le nonce, l’AAD, `expires_at` et `server_time`. Aucun contenu en clair ne doit être produit par l’API. Le serveur reste l’unique autorité pour décider si le contenu est expiré.

Pour un contenu en lecture unique disponible, cette requête crée une réservation temporaire associée à l’identifiant de réservation fourni et retourne en plus un challenge de consommation et `unconfirmed_opens`. Pour un contenu en lecture unique réservé, elle retourne à nouveau le payload et le même challenge de consommation si l’identifiant de réservation fourni correspond à la réservation active ; sinon elle retourne `409` avec le délai de réservation restant, sans payload. Pour un contenu indisponible ou une preuve invalide, elle retourne la réponse générique `404`.

### `POST /api/v1/pastes/{id}/status`

Corps : le challenge d’usage `status`, `access_pk` et sa signature par la clé d’accès ; la vérification a lieu avant tout accès au stockage (§6.3.1).

Retourne l’AAD (qui porte `read_once`, les paramètres KDF et `consume_pk`), `expires_at`, `server_time` et, en lecture unique, l’état `available` ou `reserved`, le délai restant de réservation et `unconfirmed_opens`. Cet endpoint ne retourne jamais le ciphertext et ne crée jamais de réservation. Il sert à l’écran « Révéler », à la vérification locale de la phrase secrète avant réservation (§6.3.1) et à la commande CLI `metadata`.

### `POST /api/v1/pastes/{id}/consume`

Confirme la lecture unique après vérification de l’identifiant de réservation, du challenge de consommation et de sa signature par la clé de consommation Ed25519 (§6.3.1).

Le corps JSON doit contenir `access_pk`, l’identifiant de réservation, le challenge reçu et la signature Ed25519.

Avant tout accès au stockage, le serveur vérifie que les 8 premiers octets de l’identifiant (`A`) correspondent à `tronc64(SHA-256("sp-proto/v1/id" ‖ 0x00 ‖ access_pk))` ; un tiers qui ne connaît que l’identifiant (par exemple par le lien de gestion ou des logs) ne déclenche donc aucun accès disque. Le serveur lit ensuite `meta.json` et `state.json` sans verrou exclusif, compare `access_pk` à celle de l’AAD stockée dans `meta.json` et le hash de l’identifiant de réservation à celui de la réservation active **ou de la réservation ayant consommé** (état `consumed`, pour le rejeu idempotent), et ne prend le verrou exclusif qu’après ces contrôles ; sous verrou, il relit l’état, revérifie l’identifiant de réservation, l’expiration de la réservation et l’égalité du challenge, puis vérifie la signature et applique la transition ou renvoie le succès du rejeu idempotent.

Cette opération doit être atomique et idempotente pour la réservation concernée : le renvoi exact de la preuve qui a consommé (même réservation, même challenge, même signature) retourne le même succès (`200`) tant que l’état `consumed` est conservé (§6.3.1). Toute autre preuve invalide, expirée ou réutilisée est rejetée par la réponse générique `404`, sans information supplémentaire.

### `DELETE /api/v1/pastes/{id}`

Supprime le contenu après vérification du jeton transmis dans l’en-tête `X-Deletion-Token`. Cet en-tête doit être absent des logs applicatifs, des traces, des métriques et des réponses d’erreur.

Traitement, dans cet ordre :

1. sans aucun accès au stockage : rejet (`404`) d’un en-tête absent ou dont le décodage base64url canonique ne fait pas 32 octets, puis calcul de `SHA-256(token)` et vérification que les octets 9 à 16 de l’identifiant (`D`) égalent `tronc64(SHA-256("sp-proto/v1/id-delete" ‖ 0x00 ‖ SHA-256(token)))` ; sinon, réponse `404` sans lire le disque ;
2. lecture de `meta.json` et comparaison en temps constant (`hash_equals`) de `SHA-256(token)` avec le hash stocké ;
3. suppression sous verrou (§9.4.1).

Le jeton brut n’est jamais conservé. La suppression réussie retourne `204` ; un jeton invalide ou un contenu indisponible retournent la réponse générique `404`. Un tiers qui connaît l’identifiant sans le jeton ne peut donc pas savoir si le contenu existe.

### `GET /healthz`

Retourne uniquement l’état technique minimal du service. Ne pas y exposer la version détaillée, la configuration, les secrets ou des informations d’infrastructure.

### Codes de réponse

| Endpoint | Succès | Erreurs spécifiques |
|---|---|---|
| `POST /api/v1/pastes` | `201` (création), `200` (rejeu idempotent) | `400` corps, AAD ou en-tête `Idempotency-Key` invalide, `413`, `415`, `422` clé d’idempotence réutilisée avec un autre corps, `429`, `503` quota ou seuil de stockage atteint |
| `POST …/challenge` | `200` avec `challenge` et `expires_in` | `400`, `429` |
| `POST …/status` | `200` | `404` générique, `429` |
| `POST …/open` | `200` | `404` générique, `409` réservation en cours (après preuve valide), `429` |
| `POST …/consume` | `200` (y compris rejeu exact) | `404` générique, `429` |
| `DELETE …/{id}` | `204` | `404` générique (y compris contenu `reserved` ou `consumed` dont le jeton est invalide), `429` |
| `GET /healthz` | `200` | `503` |

Un `DELETE` avec un jeton valide supprime le contenu quel que soit son état (`available`, `reserved` ou `consumed`) ; une réservation en cours est alors annulée et la consommation ultérieure échoue avec `404`.

### Règles API communes

- réponses JSON strictement typées ;
- erreurs au format `application/problem+json` (RFC 9457), avec des champs `type` et `title` génériques, sans détail interne ni identifiant de contenu ;
- pour les endpoints recevant un corps, accepter uniquement `application/json` ; l’endpoint de création doit contenir un payload déjà chiffré ; les uploads, pièces jointes et requêtes `multipart/form-data` sont refusés ;
- validation stricte des tailles et formats selon §8.2.3 ;
- erreurs génériques ne révélant pas l’existence ou l’état d’un contenu sensible à qui ne prouve pas la possession du lien ;
- utiliser le même statut public générique `404` pour un identifiant inexistant, expiré, consommé, rendu indisponible ou une preuve d’accès invalide ;
- `409` (réservation en cours) uniquement après une preuve d’accès valide ;
- `413` pour un corps trop volumineux, `415` pour un type de contenu refusé, `429` pour le rate limiting, `503` avec `Retry-After` pour un quota de stockage atteint ;
- contrôle de concurrence pour la lecture unique ;
- toutes les réponses de l’API avec `Cache-Control: no-store` ;
- aucune méthode `GET` de l’API ne modifie l’état d’un contenu ;
- protection contre le rejeu et les requêtes excessives ;
- en-têtes de sécurité sur toutes les réponses.

## 11. Sécurité applicative

Les exigences de durcissement HTTP, de cryptographie, de stockage et de rendu sont définies en §7.5 et ne sont pas répétées ici. Exigences complémentaires :

- cookies absents ; si un cookie devenait nécessaire, il serait `Secure`, `HttpOnly`, `SameSite=Strict` et toute action l’utilisant serait protégée contre le CSRF ;
- validation côté serveur de chaque métadonnée ;
- limites de taille sur le corps HTTP, les headers et les paramètres ;
- logs sans payload, clé, phrase secrète ou URL complète ;
- logs et traces sans les en-têtes `X-Deletion-Token`, `Idempotency-Key`, `Authorization` éventuel ou autre secret de transport ;
- messages d’erreur ne révélant pas de données internes ;
- dépendances verrouillées et mises à jour régulièrement ;
- analyse SAST, dépendances et secrets dans la CI.

## 12. UX et parcours principaux

### Parcours A — créer et partager

1. L’utilisateur arrive sur une page d’édition sans inscription.
2. Il saisit ou colle son texte.
3. L’interface indique que le texte sera chiffré localement.
4. Il vérifie la ligne de réglages (expiration, lecture unique, phrase secrète, taille), applique éventuellement un préréglage ou modifie un réglage.
5. Il clique sur « Chiffrer et créer le lien » ; la validation locale doit être réussie.
6. Le navigateur chiffre le texte.
7. Seul le payload chiffré est envoyé au serveur ; en cas d’échec, l’utilisateur peut réessayer sans rechiffrement ou annuler.
8. Le texte est retiré de l’éditeur ; le lien est présenté avec les actions Copier, Copier avec un message, partage natif, QR code et Nouveau contenu, et le lien de gestion reste accessible derrière « Gérer / Supprimer ».
9. La clé n’est jamais affichée séparément si elle est déjà incluse dans le lien.

### Parcours B — consulter

1. Le destinataire ouvre le lien.
2. Le navigateur vérifie que la clé du fragment est présente et de longueur valide, sinon il affiche « lien incomplet ».
3. Le navigateur dérive la clé d’accès et prouve la possession du lien ; il obtient le statut et l’AAD (§6.3.1).
4. En lecture unique, l’écran « Révéler le contenu » s’affiche ; si une phrase secrète est requise, le destinataire la saisit et elle est vérifiée localement, sans réserver le contenu.
5. Sur action explicite (lecture unique) ou directement (lecture multiple), l’application récupère uniquement le ciphertext.
6. Le navigateur déchiffre et vérifie l’intégrité ; hors lecture unique, la phrase secrète est demandée à cette étape si nécessaire.
7. Si des ouvertures antérieures non confirmées sont signalées, un avertissement est affiché avant le contenu.
8. Le contenu est rendu localement.
9. En lecture unique, la consommation est finalisée selon le protocole prévu.

### Parcours C — erreur

Les erreurs doivent être compréhensibles sans révéler d’information sensible :

- lien incomplet ou tronqué par une messagerie, détecté localement ;
- contenu indisponible, sans distinguer un lien inexistant, expiré ou déjà consommé ;
- lecture unique en cours d’ouverture ailleurs, avec délai avant nouvelle tentative ;
- phrase secrète incorrecte, sans consommation du contenu ;
- perte de connexion, avec nouvelle tentative explicite ;
- format non supporté ;
- chiffrement indisponible ;
- service temporairement indisponible.

## 13. Performance et compatibilité

Objectifs :

- première interface utilisable en moins de 2 secondes sur une connexion mobile correcte ;
- aucun framework ou dépendance externe chargé depuis un CDN en production ;
- bundle frontend raisonnable et analysé à chaque build ;
- chargement différé du rendu Markdown, de la coloration syntaxique, du générateur de QR code et du module Argon2id, qui ne sont pas nécessaires au premier affichage de l’éditeur ;
- affichage d’un contenu en lecture multiple en deux allers-retours au plus après le chargement de la page (`status` puis `open`, les challenges étant fournis dans la page) ; une lecture unique y ajoute la consommation, et un challenge expiré un aller-retour de renouvellement ; la signature Ed25519 et les dérivations HKDF sont négligeables devant la latence réseau ;
- support des deux dernières versions majeures de Chrome, Firefox, Safari et Edge ;
- support des navigateurs mobiles iOS et Android courants ;
- matrice de compatibilité vérifiée pour AES-GCM, HKDF, Ed25519, WebAssembly Argon2id et les fonctionnalités de clipboard ;
- dégradation propre si Web Crypto API, stockage local ou fonctionnalités requises ne sont pas disponibles ; aucune dégradation silencieuse ne doit réduire le niveau cryptographique.

Objectifs de capacité de référence, à valider par les tests de charge de Phase 3 sur une machine de référence documentée (2 vCPU, 2 Go RAM, SSD local) :

- 100 000 contenus actifs et 10 Gio de stockage sans dégradation notable ;
- 50 créations par seconde et 200 ouvertures par seconde en pointe ;
- latence p95 inférieure à 200 ms pour les endpoints API hors temps réseau ;
- purge de 100 000 contenus expirés en moins de 5 minutes sans bloquer le service.

## 14. Observabilité et confidentialité

L’observabilité doit permettre de diagnostiquer la disponibilité sans collecter le contenu.

Autorisé :

- durée et statut des requêtes ;
- taux d’erreur ;
- consommation mémoire et CPU ;
- taille du payload chiffré ;
- métriques agrégées et non identifiantes.

À éviter ou désactiver par défaut :

- IP conservée durablement ; le rate limiting n’utilise qu’un HMAC de l’IP à durée de vie courte (§9.2) ;
- URL complète ;
- fragment URL ;
- User-Agent détaillé si non nécessaire ;
- identifiants corrélables entre requêtes ;
- analytics tiers.

La politique de conservation des logs doit être configurable et documentée.

## 15. Déploiement

Livrables attendus :

- image Docker officielle ;
- fichier Docker Compose de démonstration ;
- le fichier Docker Compose ne doit déclarer aucun service PostgreSQL, MySQL, SQLite, Redis, Valkey ou autre base de données ;
- image PHP-FPM et configuration Nginx ou Apache ;
- répertoire de thèmes personnalisables et exemple de thème ;
- fichier `config.php.example` et documentation complète des options ;
- configuration des secrets et des paramètres d’exécution autorisés par variables d’environnement et Docker Secrets (§9.5), les autres options passant par `config.php` ;
- documentation HTTPS avec reverse proxy ;
- procédure de sauvegarde et restauration ;
- procédure de mise à jour et retour arrière ;
- configuration de référence du stockage de fichiers et de ses permissions ;
- mécanisme de versionnement du format des fichiers et stratégie de compatibilité ;
- SBOM et procédure de vérification des artefacts signés ;
- configuration de durcissement HTTP documentée ;
- image exécutée obligatoirement avec un utilisateur non root ;
- filesystem en lecture seule lorsque compatible ;
- healthcheck et arrêt propre ;
- planification de la purge en conteneur : un service dédié du Docker Compose, basé sur la même image, avec le même utilisateur non root, le même filesystem racine en lecture seule et le même volume de stockage, exécute `app:purge-expired` toutes les 60 secondes dans une boucle supervisée ; aucun démon `cron` n’est requis dans l’image ; l’équivalent systemd est un timer (`OnUnitActiveSec=60s`).

### 15.1 Outillage d’exploitation

L’exploitation se fait uniquement par la console, la configuration et les outils système (aucun backoffice). Les commandes suivantes sont attendues (priorité Should) :

- `app:boot --dry-run` exécute tous les contrôles d’amorçage sans rien créer, écrire ni supprimer : un répertoire absent devient un avertissement, contrôlé par son plus proche parent existant, et le test de `rename()`/`link()`, qui exige des fichiers de test, est omis (`EXG-OPS-001`) ;
- `app:boot` signale en avertissement un fichier de secret ou une configuration lisibles par tous les comptes, des inodes non mesurables et un `post_max_size` PHP inférieur à `http.max_request_bytes`, et refuse les répertoires de stockage accessibles à d’autres comptes (mode `0700` exigé) (`EXG-OPS-002`) ;
- `app:boot` et `app:config:check` acceptent `--format=json` (un document JSON unique, sans jamais le secret) ; les codes de sortie sont documentés : `0` succès, `1` échec bloquant ou configuration invalide, `2` erreur d’usage ou, pour `app:config:check`, configuration valide mais non amorcée ; `app:config:check` est en lecture seule et sert au healthcheck du conteneur (`EXG-OPS-003`) ;
- les procédures d’exploitation (unités systemd, sauvegarde et restauration avec test régulier, permissions, mise à jour et retour arrière, vérification après déploiement, dépannage, procédure d’incident) sont documentées dans le README administrateur (`EXG-OPS-004`) ;
- les situations d’exploitation autrement invisibles sont journalisées sous forme d’événements (`health_stale`, `boot_marker_mismatch`, `purge_failures`, en plus de `quota_alert`), au plus une fois par minute et par worker pour ceux émis par les requêtes, sans identifiant, chemin ni adresse (`EXG-OPS-005`) ;
- `app:secret:generate --output=<fichier>` écrit le secret dans un nouveau fichier (mode `0600`, ou `0640` avec `--group-readable`) sans l’afficher ; un fichier existant n’est remplacé, atomiquement, qu’avec `--force` (`EXG-OPS-006`) ;
- hygiène Docker : healthcheck du service applicatif conditionné à l’état d’amorçage, signal d’arrêt adapté du service de purge, exclusions du contexte de build (données, rapports, artefacts), espace temporaire suffisant pour Nginx et journal d’erreurs Nginx limité au niveau `emerg` (`EXG-OPS-007`) ;
- validation locale en Docker (`tools/docker/qa.sh`) et test de fumée de la pile Compose (`tools/docker/smoke.sh`), exécutés avant chaque version (`EXG-OPS-008`).

## 16. Tests et validation

### 16.0 Méthode TDD obligatoire

Le développement doit suivre le cycle :

1. **Red :** écrire un test qui décrit le comportement attendu et qui échoue ;
2. **Green :** écrire l’implémentation minimale pour faire réussir le test ;
3. **Refactor :** améliorer le code sans modifier le comportement couvert.

Règles obligatoires :

- aucune fonctionnalité ne doit être fusionnée sans tests associés ;
- les tests doivent être écrits avant le code de production pour les nouvelles fonctionnalités ;
- chaque exigence fonctionnelle doit avoir au moins un test d’acceptation ;
- chaque règle de sécurité doit avoir un test automatisé lorsque cela est techniquement possible ;
- le protocole cryptographique doit être développé à partir de vecteurs de test avant l’implémentation ;
- le frontend, le backend PHP et la CLI doivent partager des tests de contrat sur le format chiffré ;
- les tests doivent être déterministes et indépendants de l’ordre d’exécution ;
- les tests ne doivent jamais utiliser de secrets ou de données personnelles réelles ;
- la CI doit bloquer toute fusion si les tests, l’analyse statique ou les contrôles de sécurité échouent ;
- la couverture de code est un indicateur complémentaire et ne remplace pas la qualité des tests.

### 16.1 Tests fonctionnels

- création et lecture d’un texte ;
- chargement et validation du fichier de configuration ;
- comportement sûr avec une configuration invalide ;
- langue anglaise par défaut ;
- détection de la langue du navigateur si elle est supportée ;
- fallback anglais pour une langue non supportée ;
- fallback anglais conservé même si aucune langue de navigateur n’est supportée ;
- changement manuel de langue sans stockage de contenu en clair ;
- direction (`ltr` ou `rtl`) et attributs `lang` corrects ;
- affichage correct des langues LTR et RTL sur mobile et desktop, sans débordement ni texte tronqué ;
- parcours de création utilisable sans compte en quelques étapes ;
- prévention des doubles clics et doubles créations ;
- conservation de la saisie après une erreur réseau ;
- avertissement avant fermeture, rechargement ou navigation avec une saisie non publiée ;
- absence de sauvegarde du brouillon dans les stockages persistants du navigateur ;
- bouton de collage depuis le presse-papier déclenché uniquement par une action utilisateur ;
- refus ou indisponibilité de l’API Clipboard avec maintien du collage manuel ;
- absence de lecture automatique, de persistance et de journalisation du presse-papier ;
- refus des fichiers joints, uploads, champs fichier, glisser-déposer de fichiers et requêtes `multipart/form-data` ;
- messages d’erreur compréhensibles et récupérables ;
- boutons et zones tactiles conformes sur mobile ;
- masquage du contenu lors du passage en arrière-plan lorsque possible ;
- expiration à chaque durée ;
- lecture unique avec deux requêtes concurrentes, dont une seule obtient le payload ;
- réponse générique identique pour un contenu inexistant, expiré ou consommé, et pour une preuve d’accès invalide ;
- réponse `409` « réservé » uniquement après preuve d’accès valide ;
- impossibilité d’obtenir le ciphertext, de réserver ou de consulter le statut avec le seul identifiant ;
- challenge d’accès de même forme pour un identifiant existant ou non, sans écriture disque ;
- incrément de `unconfirmed_opens` à chaque réservation échue et affichage de l’avertissement au lecteur suivant ;
- destruction d’une lecture unique après `max_unconfirmed_opens` ouvertures non confirmées ;
- endpoint `status` sans remise de payload ni réservation ;
- refus de toute création au-delà des quotas de stockage et des seuils d’espace ou d’inodes libres ;
- réservation temporaire sans confirmation ;
- libération d’une réservation échue ;
- création, lecture, suppression et purge via le stockage de fichiers sans service de base de données ;
- concurrence sur un même fichier avec `flock()` et écritures atomiques ;
- suppression ou purge concurrente d’une ouverture en attente de verrou : l’état relu après verrou empêche toute remise ou réservation d’un contenu supprimé ;
- répertoire `<id>/` vide ou incomplet traité comme supprimé ;
- aucune différence de réponse ni de temps de traitement exploitable entre un identifiant existant et inexistant sur `status` et `open` ;
- reprise de réservation renvoyant le challenge `consume` stocké ; rejeu exact de `consume` après une réponse perdue retournant le même succès ; tout autre rejeu rejeté ;
- rotation de `QUIETLINK_APP_SECRET` pendant une réservation : consommation réussie sans incrément de `unconfirmed_opens` ; challenge `open` expiré ou invalidé renouvelé par une seule nouvelle tentative ;
- preuves invalides répétées sur un identifiant : la lecture légitime n’est pas bloquée par la limite par identifiant ;
- quotas fondés sur `usage.json` sans parcours du stockage pendant les requêtes, recalcul par la purge, refus de création selon `health.json` ;
- `app:boot` : échec bloquant le démarrage de PHP-FPM pour toute configuration invalide ; réponse `503` lorsque le marqueur d’amorçage est absent ou ne correspond pas à la configuration chargée ; prise en compte d’une modification de configuration après `app:boot` ;
- aucun fichier de verrou créé par requête (contenus et rate limiting) ;
- idempotence : enregistrement publié une seule fois par `link()` après création ; créations concurrentes avec la même clé produisant un seul identifiant communiqué, le contenu perdant étant supprimé ; requête sans `Idempotency-Key` refusée ;
- arrêt entre la création du contenu et la publication de l’enregistrement : la nouvelle tentative crée un nouveau contenu sous un nouvel identifiant ; l’orphelin reste inaccessible puis est supprimé par la purge après 15 minutes, y compris pour un contenu « jamais » ;
- course entre deux requêtes de même clé et de corps différents : le perdant reçoit `422`, et son contenu est supprimé et décompté des quotas ;
- rejeu de création limité par la limite de débit propre aux rejeux ;
- échec de `link()` hors course (espace disque) : contenu supprimé, `usage.json` décrémenté, réponse `503` ;
- créations concurrentes près du quota : aucun dépassement ;
- renvoi exact de `consume` après consommation, y compris au-delà de l’expiration du contenu dans les 10 minutes : succès ;
- `DELETE` avec un identifiant connu et un jeton dont l’empreinte ne correspond pas à `D` : aucun accès au stockage ;
- purge concurrente : une seconde exécution se termine immédiatement tant que `purge.lock` est pris ;
- codes de réponse conformes au tableau de §10 pour chaque endpoint ;
- `DELETE` d’un contenu `reserved` : la consommation ultérieure échoue avec `404` ;
- base64url non canonique (bits de remplissage non nuls, padding, caractères hors alphabet) rejeté par le frontend, le backend et la CLI ;
- contenu contenant des surrogates isolés : créé dans le navigateur et déchiffré par la CLI ;
- resynchronisation du compte à rebours après mise en veille ou retour de l’onglet au premier plan ;
- aucune image rendue depuis le Markdown, quelle que soit sa source ;
- CSP de la page sans `'wasm-unsafe-eval'` dans toutes les configurations, et worker Argon2id servi avec `'wasm-unsafe-eval'` même lorsque `allow_passphrase = false` ; lecture d’un contenu protégé après désactivation de `allow_passphrase` ;
- QR code Wi-Fi avec SSID et mot de passe contenant `\ ; , : "` correctement échappés ;
- CLI : invites lues sur `/dev/tty` lorsque l’entrée standard est un tube, et refus des combinaisons d’entrées non admises ;
- nouvelle soumission des mêmes octets par un détenteur du lien, avant ou après suppression, consommation ou expiration : un nouvel identifiant est attribué et l’ancien lien reste indisponible ;
- identifiant attribué par le serveur, dont les octets 1 à 8 valent `A` (empreinte de `access_pk`), les octets 9 à 16 valent `D` (empreinte du hash du jeton de suppression) et les octets 17 à 24 sont aléatoires (`R`) ; rejet d’une preuve dont `access_pk` ne correspond pas aux 64 premiers bits ;
- vérification par le lecteur que `access_pk` de l’AAD est celle dérivée du lien ;
- identifiant de réservation généré par le client : réponse d’`open` perdue puis nouvelle tentative reprenant la réservation, sans `409` ni incrément de `unconfirmed_opens` ;
- rejeu idempotent d’une création après plusieurs minutes, quotas atteints ou rate limiting actif : même réponse, sans doublon ;
- `consume` avec un identifiant connu mais sans `access_pk` correspondante : aucun accès au stockage ;
- le client rejette un identifiant renvoyé dont `A` ou `D` ne correspondent pas à `access_pk` ou au hash du jeton de suppression, et le lecteur détecte un lien altéré avant tout appel ;
- AAD d’`open` différente de celle de `status` : échec d’intégrité ;
- création refusée pour une `access_pk` ou une `consume_pk` d’ordre faible ou non canonique ;
- corps de création contenant un champ `id` : refusé (`400`) ;
- verrou obtenu sur un `state.lock` supprimé puis recréé : contrôle d’inode et abandon ;
- purge refusée sans marqueur d’amorçage valide ; création refusée (`503`) avec un `health.json` périmé ;
- rate limiting derrière un reverse proxy : adresse client prise uniquement depuis un proxy de confiance, `X-Forwarded-For` ignoré sinon, adresses IPv4 mappées en IPv6 normalisées ;
- `open` et `status` avec une preuve invalide : aucun accès au stockage ;
- nouvelle tentative unique sur tout `404` d’`open` ou de `status` ;
- secret d’instance fourni par `QUIETLINK_APP_SECRET_FILE` et visible des workers PHP-FPM ; échec de `app:boot` et `/healthz` dégradé si les workers ne le voient pas ;
- adresses IPv6 d’un même `/64` comptées ensemble par le rate limiting ; absence de remise à zéro des compteurs au changement de jour UTC ;
- refus des codes d’expiration hors de l’ensemble fermé, dans la configuration comme dans l’AAD ;
- requête de création invalide ou hors quota : aucun fichier d’idempotence créé ;
- `health.json` périmé signalé par `/healthz` ;
- échec de consommation après déchiffrement : contenu maintenu affiché avec un message ;
- reprise après rechargement d’une lecture unique protégée par phrase secrète : écran « Révéler » réaffiché avec le délai restant ;
- champ de phrase secrète masqué par CSS ou repli `type="password"` selon `CSS.supports()`, sans vocalisation de la valeur ;
- barre d’action mobile ne masquant jamais l’élément ayant le focus (`scroll-padding-bottom`) ;
- CLI sans terminal de contrôle : refus de consommer sans `--yes` ; `--passphrase-file` accepté sous `/run/secrets/` ;
- `state.lock` jamais recréé par une requête : une ouverture concurrente d’une suppression ne laisse aucun répertoire résiduel ;
- suppression de `payload.bin` sous verrou immédiatement après la consommation ;
- lecture d’un contenu protégé par phrase secrète sur un navigateur sans Argon2id : message d’incompatibilité sans appel à `open` ;
- reprise après présence d’un fichier temporaire ou interruption pendant un renommage ;
- contenu orphelin (créé sans enregistrement d’idempotence publié) inaccessible et supprimé par la purge ;
- purge des enregistrements d’idempotence au-delà de `idempotency_max_ttl`, y compris pour un contenu sans expiration ;
- nouvelle tentative après timeout avec un corps identique octet pour octet ;
- cohérence et restauration d’une sauvegarde réalisée depuis un snapshot ou après arrêt/mise en lecture seule du service ;
- refus des chemins sortant du répertoire de stockage et des liens symboliques non autorisés ;
- rejet d’une preuve de consommation invalide ou réutilisée ;
- confirmation de consommation uniquement avec la clé correcte ;
- phrase secrète correcte et incorrecte ;
- génération d’une phrase secrète avec aléa cryptographiquement sûr ;
- absence de remplacement silencieux d’une phrase secrète déjà saisie ;
- création répétée avec la même clé d’idempotence sans doublon ;
- rejet d’une clé d’idempotence réutilisée avec une requête différente ;
- suppression manuelle ;
- contenu Markdown et code ;
- copie d’un bloc de code sans copier les autres blocs ;
- affichage relatif et localisé de l’expiration à partir du timestamp serveur ;
- séparation visuelle et confirmation du lien de suppression ;
- transmission du jeton de suppression uniquement dans l’en-tête dédié et absence de ce jeton dans les logs ;
- format `expires_at` ISO 8601 UTC et affichage localisé ;
- présence de `server_time` et calcul du compte à rebours sans dépendre directement de l’horloge locale ;
- états textuels accessibles pendant Argon2id, le chiffrement et le déchiffrement ;
- exécution des opérations cryptographiques coûteuses sans blocage de l’interface lorsque le Web Worker est disponible ;
- blocage ciblé du pull-to-refresh dans l’éditeur et la lecture sans blocage global du document ;
- CLI séparant les commandes `metadata` et `decrypt` avec lecture d’URL depuis stdin ;
- `spellcheck`, `autocorrect`, `autocapitalize` et `autocomplete` désactivés sur l’éditeur et le champ de phrase secrète ;
- jauge et blocage calculés sur l’enveloppe sérialisée, y compris pour un texte riche en caractères de contrôle, et acceptation serveur de toute enveloppe acceptée par le client ;
- vérification locale de la phrase secrète avant réservation d’une lecture unique, sans incrément de `unconfirmed_opens` en cas d’erreur ;
- reprise de réservation après rechargement de l’onglet, sans incrément de `unconfirmed_opens` ni alerte ;
- écran « Révéler » : aucune requête `open` avant l’action explicite ;
- avertissement avant fermeture d’une lecture unique affichée et masquage après inactivité ;
- détection d’un lien de partage ou de gestion tronqué sans requête serveur ;
- page de gestion : réponse identique pour une suppression réussie, un contenu expiré ou un jeton invalide ;
- réglages en une ligne, préréglages « Secret » et « Partage » ramenés aux durées autorisées ;
- « Copier avec un message » sans phrase secrète ni lien de gestion, partage natif limité au lien de partage ;
- retrait du texte en clair de l’éditeur à l’affichage de l’écran de résultat ;
- copie champ par champ et masquage par défaut des champs sensibles des templates ;
- phrase secrète générée en mots, d’au moins 66 bits d’entropie, dans la langue active ;
- gestion du focus à chaque changement d’écran et annonces du compte à rebours limitées aux seuils ;
- renouvellement transparent d’un challenge expiré sur l’écran « Révéler » ;
- confirmation avant « Nouveau contenu » ou fermeture lorsque le lien de gestion n’a jamais été copié ni affiché ;
- message « Le contenu n’a pas été envoyé » avec « Réessayer » sans rechiffrement et « Annuler » texte conservé ;
- validation locale avant chiffrement et absence de toute requête en cas d’échec ;
- ligne de réglages incluant la taille, et préréglage « Secret » absent si la lecture unique est désactivée ;
- double saisie de la phrase secrète masquée, non demandée pour une phrase générée ou affichée ;
- `Échap` masque le contenu, ferme les aperçus et vide la sélection lorsqu’aucun panneau n’est ouvert ;
- succès annoncés par `aria-live` sans déplacement du focus, focus déplacé uniquement lors d’un changement d’écran ou d’une erreur de validation ;
- barre d’action mobile contextuelle n’exposant qu’une action principale par écran ;
- enveloppe proche de la limite de 1 Mio : interface réactive, coloration syntaxique désactivée au-delà du seuil et activable explicitement ;
- zoom navigateur à 200 % et 400 % sans perte d’information ni défilement horizontal (WCAG 1.4.4 et 1.4.10), navigation au clavier seul et avec un lecteur d’écran ;
- réseau lent (profil 3G) et coupure pendant l’envoi, le `status`, l’`open` et la consommation ;
- CLI : `decrypt` consomme une lecture unique après confirmation, sans option de lecture sans consommation ;
- refus de démarrage pour une configuration incohérente (`allow_forever` avec `max_retention`, durée par défaut non autorisée, TTL hors bornes, secret absent ou trop court) ;
- CLI : invite de phrase secrète sans écho, `--passphrase-stdin` explicite, refus de toute phrase secrète en argument ou variable d’environnement, échec sans TTY ni option ;
- avertissement « ne pas ouvrir le lien pour le tester » sur l’écran de résultat d’une lecture unique ;
- confirmation avant application d’un template sur un éditeur non vide ;
- calcul du compte à rebours avec correction de l’aller-retour réseau et horloge monotone ;
- changement de thème sans modification du code métier ;
- thèmes clair, sombre et personnalisé ;
- contraste et lisibilité sur chaque thème fourni ;
- copier, coller, QR code ;
- QR code généré localement avec fond blanc, contraste et zone de silence conformes ;
- QR code limité au lien de partage et jamais au lien de gestion ou de suppression ;
- impression désactivée par défaut (`ui.allow_print = false`), avertissement et feuille `@media print` si l’option est explicitement activée ;
- filtrage des schémas de liens Markdown et indicateur externe accessible ;
- absence de prévisualisation réseau automatique des liens Markdown ;
- manifest optionnel sans Service Worker, sans mode hors ligne et sans `display: standalone` ;
- parcours mobile ;
- erreur réseau et reprise ;
- absence de compte.

### 16.2 Tests cryptographiques

- le serveur ne reçoit jamais le texte en clair ;
- la clé n’apparaît jamais dans une requête HTTP ;
- modification d’un bit du ciphertext détectée ;
- modification des métadonnées authentifiées détectée ;
- impossibilité de déchiffrer sans la clé ;
- vecteurs Argon2id frontend et CLI ;
- vecteurs de dérivation `PRK_url`, `PRK_content`, `K_enc`, `K_access_seed` et `K_consume_seed`, avec et sans phrase secrète ;
- vecteurs des paires Ed25519 d’accès et de consommation, y compris l’encapsulation PKCS#8 pour Web Crypto ;
- clés publiques et signatures des vecteurs identiques, et vérification croisée réussie, entre Ed25519 Web Crypto et le module Ed25519 JavaScript de repli, et ouverture d’un contenu sur un navigateur sans Ed25519 natif ;
- vecteurs du message signé (`"sp-proto/v1/proof" ‖ 0x00 ‖ challenge`) et rejet d’un challenge utilisé pour un autre usage, un autre identifiant, expiré ou au HMAC invalide ;
- normalisation NFC identique de la phrase secrète entre le navigateur et la CLI (`ext-intl`) ;
- sérialisation canonique PHP de l’AAD identique à la sérialisation JavaScript sur l’ensemble des vecteurs, et rejet des AAD à clés dupliquées par comparaison octet à octet ;
- vecteurs de canonicalisation RFC 8785 de l’AAD et rejet des AAD non canoniques, à clés dupliquées, inconnues ou manquantes, à nombres non entiers ou à tableaux ;
- détection de la substitution par le contenu d’un lien de `K_url` différente (`access_pk` et `K_enc` différentes) et de la désactivation de la lecture unique dans l’AAD ;
- vecteurs Argon2id avec `p = 1` et sel de 16 octets, identiques entre le module navigateur et `sodium_crypto_pwhash` ;
- refus des paramètres Argon2id hors bornes ;
- chiffrement AES-256-GCM de la CLI via `ext-openssl` conforme aux vecteurs du navigateur ;
- comparaison des résultats Web Crypto, de l’implémentation locale validée et de la CLI ;
- désactivation explicite du mode phrase secrète si le module KDF validé est indisponible ;
- vecteurs de test partagés entre frontend, backend et CLI ;
- tests de compatibilité entre versions de protocole supportées.

### 16.3 Tests de sécurité

- revue des dépendances ;
- scan de secrets ;
- SAST et DAST ;
- tests XSS et injection Markdown ;
- tests de rate limiting ;
- tests d’énumération d’identifiants ;
- tests de rejeu ;
- tests de non-énumération des contenus et réponses publiques uniformes ;
- tests d’idempotence et de rejet des clés d’idempotence réutilisées ;
- vérification du masquage du jeton de suppression dans les logs, traces et métriques ;
- vérification des permissions du stockage et de l’absence d’accès direct depuis `public/` ;
- vérification qu’aucun service PostgreSQL, MySQL, SQLite, Redis, Valkey ou autre base de données n’est requis par le déploiement ;
- audit des logs ;
- audit des headers HTTP ;
- vérification de la CSP de référence, y compris `worker-src`, `wasm-unsafe-eval`, l’absence de `blob:` et la présence de l’en-tête CSP sur les réponses des scripts de workers ;
- vérification qu’aucun attribut `style` n’est produit par le rendu Markdown, la coloration syntaxique ou le QR code ;
- vérification de la `Permissions-Policy`, y compris le presse-papier ;
- vérification qu’aucune ressource tierce n’est chargée ;
- vérification qu’aucun fichier PHP ou de configuration n’est présent dans `public/` ;
- vérification qu’aucune adresse IP en clair n’est écrite par le rate limiting ;
- vérification des règles `Cache-Control` et absence de cache des contenus sensibles ;
- tests derrière reverse proxy et avec cache navigateur ;
- tests de compatibilité sur la matrice de navigateurs et de capacités cryptographiques ;
- vérification de l’image Docker non-root (obligatoire) et du filesystem en lecture seule ;
- contraste des bordures de contrôles (≥ 3:1) et visibilité de l’anneau de focus décalé sur le bouton principal ;
- audit Composer, npm ou équivalent et génération du SBOM ;
- vérification de la signature et des hashes des releases ;
- test de compilation reproductible autant que possible.

### 16.4 Critères d’acceptation V1

La V1 est considérée comme livrable si :

- aucun texte en clair n’est reçu ou stocké par l’API ;
- aucun fichier joint, upload, transfert de fichier ou requête `multipart/form-data` n’est accepté ;
- le chiffrement et le déchiffrement sont couverts par des tests automatisés ;
- la lecture unique résiste aux accès concurrents ;
- la lecture unique utilise uniquement les états `available`, `reserved` et `consumed`, l’état technique `deleted` n’étant écrit que pendant une suppression ;
- aucun contenu ne peut être créé, recréé ou remplacé sous un lien existant, y compris par un détenteur du lien et après suppression, consommation ou expiration ;
- les liens expirés ne donnent plus accès au ciphertext ;
- aucune URL complète ou clé n’est enregistrée dans les logs applicatifs ;
- aucun jeton de suppression brut n’est stocké ou journalisé ;
- les créations répétées avec la même clé d’idempotence ne créent pas de doublon ;
- les erreurs publiques ne permettent pas de distinguer un identifiant inexistant, expiré ou consommé ;
- aucun service de base de données n’est installé ou requis ;
- le stockage persistant est un volume de fichiers local, hors de la racine web, avec permissions restrictives ;
- les headers de sécurité obligatoires sont présents en production ;
- aucune ressource tierce n’est chargée par l’application ;
- le conteneur de production n’est pas exécuté en root ;
- les dépendances et artefacts de release sont vérifiables ;
- l’installation administrateur est reproductible en suivant `README-admin.md` ;
- l’installation développeur et l’exécution des tests sont reproductibles en suivant `README-developer.md` ;
- les fonctionnalités livrées sont traçables par des tests écrits avant leur implémentation ;
- l’application fonctionne sur mobile et desktop ;
- les parcours principaux respectent l’objectif WCAG 2.2 AA ;
- l’installation Docker est documentée et reproductible ;
- une revue de sécurité interne formalisée a été réalisée avant production, et l’audit externe ciblé (§7.5) avant la première version publique ;
- aucun ciphertext ni aucune métadonnée n’est remis sans preuve de possession du lien ;
- toute ouverture non confirmée d’une lecture unique est signalée au lecteur suivant, sans faux positif dû à une erreur de phrase secrète, à un rechargement du même onglet pendant la réservation ou à une rotation du secret d’instance ;
- un tiers qui ne connaît que l’identifiant ne peut ni savoir si le contenu existe, ni le réserver, ni bloquer sa lecture, y compris par le rate limiting ;
- la configuration est validée par `app:boot` avant tout démarrage de PHP-FPM, et toute requête est refusée si la configuration chargée diffère de la configuration validée ;
- une lecture unique n’est jamais réservée avant l’action explicite « Révéler » ni avant la vérification locale de la phrase secrète ;
- aucun champ de saisie de contenu ou de phrase secrète n’active la correction orthographique, l’autocorrection ou l’autocomplétion ;
- les tests utilisateurs de Phase 3 ont été menés et leurs problèmes bloquants corrigés ;
- les quotas de stockage et la rétention bornée de l’idempotence empêchent toute croissance illimitée du stockage ;
- toutes les exigences Must de la matrice de traçabilité (§0.2) sont couvertes par des tests verts ;
- un contenu peut être ouvert sur tous les navigateurs de la matrice de compatibilité, y compris sans Ed25519 natif ;
- aucune opération concurrente ne permet de servir ou réserver un contenu supprimé ;
- le collage depuis le presse-papier nécessite une action explicite et ne persiste jamais le contenu ;
- les états cryptographiques sont compréhensibles, accessibles et ne bloquent pas l’interface sans indication ;
- l’expiration affichée tient compte de `server_time`, tandis que l’expiration effective est vérifiée côté serveur ;
- le QR code est généré localement et ne contient jamais le lien de gestion ou de suppression ;
- l’impression reste désactivée par défaut et ne peut être déclenchée automatiquement ;
- les liens Markdown dangereux sont bloqués et les liens externes ne sont pas prévisualisés automatiquement ;
- aucun Service Worker ni mode hors ligne n’est requis ou livré en V1, et l’origine reste visible si un manifest est fourni.

## 17. Livrables

- application web frontend ;
- API backend ;
- CLI ;
- PHAR CLI versionné et image Docker CLI ;
- format de protocole documenté, avec vecteurs de test publics ;
- spécification OpenAPI 3.1 de l’API ;
- matrice de traçabilité exigences ↔ tests ;
- tests unitaires, intégration, E2E et sécurité ;
- tests PHPUnit et analyse statique PHP ;
- catalogues de traduction anglais, français, espagnol, italien et arabe ;
- image Docker et Compose ;
- documentation de la stratégie de cache et commande de purge contrôlée ;
- commandes `app:boot`, `app:config:check`, `app:secret:generate` et `app:purge-expired` ; commande `app:theme:preview` (priorité Could) ;
- documentation utilisateur ;
- documentation d’installation et d’exploitation de l’instance, sans backoffice ;
- documentation sécurité et modèle de menace ;
- fichier racine `SECURITY.md` avec la politique de signalement et de traitement des vulnérabilités ;
- fichier racine `CODE_OF_CONDUCT.md` définissant les règles de participation au projet ;
- fichier racine `LICENSE` contenant le texte de l’AGPL-3.0, et mention de la licence dans chaque fichier source (en-tête SPDX `AGPL-3.0-or-later` ou `AGPL-3.0-only`, à fixer à l’initialisation du dépôt) ; l’interface fournit un lien vers les sources de la version déployée, comme l’exige l’article 13 de l’AGPL pour une utilisation en réseau ;
- politique de divulgation de vulnérabilités ;
- guide de contribution ;
- changelog et stratégie de versionnement.

### 17.1 README administrateur

Les fichiers README sont rédigés en anglais (§9.2).

Le fichier `docs/README-admin.md` doit expliquer l’installation et l’exploitation d’une instance sans interface de backoffice.

Il doit couvrir :

- prérequis système et versions supportées ;
- installation Docker et Docker Compose ;
- installation PHP-FPM, Nginx ou Apache ;
- création et permissions des répertoires ;
- configuration de `config.php` et `config.local.php` ;
- variables d’environnement et Docker Secrets ;
- génération (`app:secret:generate`) et rotation de `QUIETLINK_APP_SECRET` : une rotation invalide les challenges `open` et `status` en cours, que le client renouvelle automatiquement (une nouvelle tentative, §6.3.1) ; elle n’affecte pas les réservations en cours, dont le challenge `consume` est vérifié par comparaison avec la valeur stockée ; elle réinitialise les compteurs de rate limiting ; elle est suivie de `app:boot` et d’un rechargement de PHP-FPM ;
- configuration des thèmes et des langues ;
- configuration et maintenance du stockage de fichiers, des permissions et des verrous ;
- configuration HTTPS, HSTS et reverse proxy ;
- configuration des caches et procédure de purge ;
- nettoyage des contenus expirés ;
- rate limiting et limites techniques anti-abus ;
- politique de logs et de conservation ;
- sauvegarde et restauration ;
- mise à jour, évolution du format de stockage et retour arrière ;
- healthcheck et diagnostic ;
- durcissement Docker et PHP-FPM ;
- vérification des signatures et hashes des releases ;
- procédure de réponse en cas d’incident ;
- limites du modèle de sécurité, notamment la confiance dans le frontend servi.

Le README administrateur ne doit documenter aucun écran ou endpoint de backoffice, car ceux-ci n’existent pas en V1.

### 17.2 README développeur

Le fichier `docs/README-developer.md` doit permettre à un développeur de comprendre, tester et modifier le projet sans connaissance implicite.

Il doit couvrir :

- prérequis PHP, Symfony, Composer, Node.js, TypeScript et Vite ;
- installation locale et lancement du projet ;
- structure des répertoires ;
- architecture Symfony et responsabilités des composants ;
- fonctionnement du frontend et du chiffrement local ;
- format du protocole cryptographique ;
- format des URLs et de l’API ;
- format des enregistrements de fichiers et stratégie de lecture unique ;
- configuration locale sans secrets ;
- génération et gestion des thèmes ;
- gestion des catalogues de traduction et des langues LTR et RTL ;
- exécution des tests PHPUnit, intégration, E2E et sécurité ;
- PHPStan, linters, audits de dépendances et génération du SBOM ;
- règles interdisant le plaintext dans les logs, caches et API ;
- procédure de build frontend ;
- procédure de build reproductible et signature des releases ;
- conventions PSR et règles de nommage ;
- ajout d’un endpoint ou évolution du format de stockage ;
- ajout d’un template Markdown ;
- ajout d’une langue ou d’un thème ;
- procédure de revue de sécurité ;
- règles de contribution et de revue de code.

Les deux README doivent être testés régulièrement depuis un environnement vierge afin d’éviter une documentation obsolète.

Le README développeur doit imposer le cycle Red → Green → Refactor et documenter les commandes de test locales et CI.

### 17.3 SECURITY.md

Le dépôt doit contenir un fichier `SECURITY.md` à sa racine, visible depuis la page principale du projet. Ce fichier doit être maintenu avec chaque version publiée et rédigé en anglais.

Il doit obligatoirement préciser :

- le canal privé de signalement des vulnérabilités : GitHub Security Advisories ou canal de sécurité défini dans les métadonnées du dépôt ;
- l’interdiction de publier une vulnérabilité non corrigée dans une issue ou une discussion publique ;
- les versions supportées et la politique de correctifs de sécurité ;
- le périmètre couvert : frontend, chiffrement, API Symfony, CLI PHP, stockage de fichiers, verrouillage, purge, Docker et configuration ;
- les limites connues du modèle de menace, notamment un frontend JavaScript modifié par une instance compromise ;
- l’obligation de ne jamais joindre de texte secret, clé, phrase secrète, lien complet ou contenu utilisateur dans un rapport ;
- l’obligation de tester uniquement une instance contrôlée par le chercheur, sans déni de service, scraping ou accès à des données tierces ;
- les délais cibles d’accusé de réception, de triage et de communication ;
- la politique de coordination de la divulgation, des avis de sécurité et des éventuels identifiants CVE ;
- la politique de crédit des chercheurs, sauf demande contraire ;
- la procédure de signalement d’un incident critique nécessitant une prise de contact urgente.

`SECURITY.md` doit rappeler qu’aucune base de données n’est utilisée en V1 : les investigations doivent respecter le stockage de fichiers local, ses permissions, ses verrous et la confidentialité des ciphertexts. Le fichier ne doit contenir aucun secret, token réel, URL de partage ou donnée personnelle.

### 17.4 CODE_OF_CONDUCT.md

Le dépôt doit contenir un fichier `CODE_OF_CONDUCT.md` à sa racine. Il doit être visible depuis la page principale du projet et s’appliquer aux issues, pull requests, discussions, revues de code, événements et canaux de communication liés au projet.

Il doit préciser :

- les comportements attendus : respect, écoute, inclusion, collaboration constructive et responsabilité technique ;
- les comportements interdits : harcèlement, discrimination, intimidation, menaces, divulgation de données privées, attaques personnelles et comportement professionnel abusif ;
- le canal privé de signalement et la possibilité de demander la confidentialité ;
- les informations minimales à fournir dans un signalement, sans secret, clé, phrase secrète, lien complet ou donnée utilisateur ;
- les principes de traitement : réception, évaluation, mesures temporaires, décision et possibilité de suivi ;
- les mesures applicables en cas de violation, jusqu’à l’exclusion du projet ;
- la protection contre les représailles et la politique de crédit des signalements ;
- les coordonnées ou le canal opérationnel à utiliser avant la première publication publique.

## 18. Roadmap proposée

### Phase 0 — cadrage

- figer le modèle de menace ;
- initialiser l’architecture Symfony minimale ;
- définir l’architecture de tests et les règles TDD ;
- définir le format de protocole ;
- formaliser les preuves d’accès et de consommation de la lecture unique ;
- attribuer les identifiants d’exigence et produire la matrice de traçabilité ;
- figer la canonicalisation de l’AAD, les encodages et les limites de taille ;
- calibrer les paramètres Argon2id sur les appareils supportés ;
- définir le format des répertoires, les verrous et les écritures atomiques du stockage de fichiers ;
- produire les vecteurs cryptographiques ;
- réaliser les maquettes mobile et desktop.

### Phase 1 — prototype sécurisé

- création et lecture de texte, y compris la preuve d’accès Ed25519 (Web Crypto et module local de repli), requise pour toute lecture ;
- tests écrits avant chaque implémentation ;
- chiffrement client-side ;
- stockage d’un ciphertext ;
- expiration ;
- tests cryptographiques ;
- interface mobile minimale.

### Phase 2 — durcissement V1

- lecture unique atomique ;
- phrase secrète ;
- Markdown et syntax highlighting ;
- système de thèmes clair, sombre et personnalisable ;
- suppression ;
- QR code ;
- API et CLI ;
- CSP et headers de sécurité ;
- Docker et documentation.

### Phase 3 — validation avant production

- audit de code ;
- tests de charge contrôlés ;
- audit UX mobile ;
- tests utilisateurs modérés avec 5 à 8 participants, sans collecte de données réelles, sur des tâches chronométrées : créer une lecture unique avec phrase secrète, la transmettre, l’ouvrir sur mobile, supprimer un contenu ; les taux de réussite, durées et erreurs observées sont consignés et les problèmes bloquants corrigés avant la bêta ;
- test d’accessibilité ;
- revue de la politique de logs ;
- publication de la documentation sécurité ;
- bêta privée.

## 19. Décisions

### 19.1 Décisions prises (3 octobre 2026)

| Sujet | Décision |
|---|---|
| Licence | AGPL-3.0 |
| Frontend | TypeScript + Vite, sans framework d’interface ; tests unitaires avec Vitest |
| Markdown | markdown-it (`html: false`) + DOMPurify |
| Cryptographie navigateur | Web Crypto ; repli Ed25519 @noble/ed25519 (JavaScript pur) ; Argon2id hash-wasm dans un worker dédié |
| Argon2id par défaut | `m = 64 Mio`, `t = 3`, `p = 1`, à confirmer par la calibration de Phase 0 |
| Listes de mots | EFF « large » (`en`) ; liste `fr` construite en Phase 0 à partir de Lexique, filtrée, d’au moins 2 048 mots, avec scripts de génération versionnés ; liste anglaise utilisée pour les autres langues tant qu’une liste dédiée n’existe pas |
| Signature des releases | Sigstore (`cosign`) + attestations de provenance GitHub |
| Audit | audit externe ciblé (crypto, protocole, code client de chiffrement) avant la première version publique |
| Identité visuelle | palette de départ inspirée d’Agillia.shop validée ; logo QuietLink (bouclier et maillons) en SVG, versions claire et sombre |
| Valeurs par défaut | celles de §9.5 (conservation maximale 30 jours, option « jamais » désactivée, quotas 10 Gio / 100 000 contenus, 3 ouvertures non confirmées, réservation 60 s, idempotence 24 h, masquage après 2 minutes, seuil de coloration 200 Kio) et objectifs de charge de §13 |
| Priorités | classement Must / Should / Could de §0.3 validé |
| Honeypots | retirés de la V1 (§6.1.2) |
| Images Markdown | aucune image rendue en V1 |
| Oracle de `DELETE` | supprimé en liant le jeton de suppression à l’identifiant (§8.2) |

### 19.2 Décisions restant à prendre en Phase 0

- outil de tests E2E (par exemple Playwright) et matrice d’appareils réels ;
- format exact du stockage de fichiers et stratégie de versionnement (schémas JSON de `meta.json`, `state.json`, des enregistrements d’idempotence) ;
- format et liste des variables du système de thème ;
- motifs de détection locale de secrets pour la suggestion du préréglage « Secret » ;
- politique de journalisation par défaut ;
- variante SPDX de la licence (`AGPL-3.0-only` ou `AGPL-3.0-or-later`).

## 20. Positionnement recommandé

Le produit doit être présenté comme :

> QuietLink : un espace de partage temporaire de textes chiffrés côté client, simple, rapide et auto-hébergeable.

La promesse « hyper sécurisé » doit être remplacée dans la communication technique par des garanties vérifiables : chiffrement local, absence de texte en clair côté serveur, intégrité authentifiée, minimisation des logs, documentation du modèle de menace et audits réguliers.
