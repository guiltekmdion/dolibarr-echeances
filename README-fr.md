# Échéances pour Dolibarr : payer une facture en plusieurs fois, facturer un devis en plusieurs fois

Module gratuit fait par [Guiltek](https://www.guiltek.fr), sous licence GPL v3 ou plus. English version: [README.md](README.md).

Dans Dolibarr, une facture n'a qu'une date d'échéance et un mode de règlement. Ce module ajoute un **échéancier** : plusieurs échéances, chacune avec sa date, son montant et son mode de règlement. Il sert à deux choses :

- **une facture attendue en plusieurs paiements** (30 % à la commande et 70 % à 30 jours, 3 prélèvements...) ;
- **un devis ou une commande facturé en plusieurs fois** : chaque échéance devient une facture le jour venu, créée par une tâche planifiée.

## Ce qu'il fait

- **Onglet Échéancier** sur les factures, devis et commandes : les échéances (date, montant, mode, déjà réglé, reste, état à venir / en retard / payée / facturée), création avec un modèle ou « en N fois » (nombre, mois et jours entre deux échéances, part de la première), modification ligne par ligne, aperçu avant enregistrement. Une échéance payée, facturée ou prélevée ne peut plus changer.
- **Ce qui est payé** n'est jamais saisi à la main : les paiements, avoirs et acomptes de la facture sont donnés aux échéances par date, la plus ancienne d'abord.
- **Date d'échéance de la facture = prochaine échéance impayée**, recalculée à chaque paiement : les listes « en retard », les widgets, la relance de Dolibarr et le PDF de Dolibarr suivent les échéances, sans rien changer au cœur.
- **Trésorerie prévisionnelle d'un compte bancaire** (Banques > compte > Écritures à venir) : une ligne par échéance restant à payer, à sa date, avec son rang (`FA2611-0001 (2/3)`), au lieu de tout le reste de la facture à une seule date. Ce que les échéances ne couvrent pas (une échéance abandonnée, un échéancier qui ne fait pas le total de la facture) reste sur la ligne de la facture : le solde futur est le même que sans le module. Les échéances des devis et commandes facturés en plusieurs fois n'y sont pas : leurs factures n'existent pas encore.
- **PDF** : la condition de règlement imprimée « Selon l'échéancier » ; le tableau des échéances sous les totaux de la facture (modèle sponge ; au-delà de 12 échéances, sur deux colonnes ; au-delà de 48, celles qui restent à payer et une ligne pour les dernières), et dans la note publique des devis et commandes (tous les modèles), dans la devise du document. Clé `__INSTALLMENTS__` (une échéance par ligne, en HTML) pour la note publique, les modèles d'e-mail et les documents ODT : une note qui contient la clé dit elle-même où va l'échéancier.
- **Une facture par échéance** (devis et commandes) : la tâche « Factures des échéances » crée la facture de chaque échéance à sa date, au prorata de chaque taux de TVA du document (la dernière échéance prend le reste de chaque ligne), avec les désignations de ses lignes, dans sa devise et à son taux. Réglage par échéancier : brouillon, validée, ou validée et envoyée par e-mail (modèle choisi, obligatoire ; contact de facturation sinon e-mail du client ; PDF joint). Bouton « Facturer maintenant » et action de masse pour le faire à la main. **Jamais facturée deux fois** : une échéance n'est pas facturée si les factures déjà liées au document (ou données à ses échéances) dépasseraient son total ; elle passe « À vérifier », la raison dans sa note. Quand toutes ses échéances sont facturées, le document est classé facturé. Une facture qui n'a pas pu être validée ou envoyée met son échéance « À vérifier » jusqu'à son envoi depuis sa fiche.
- **Prélèvements** : la tâche « Prélèvements des échéances » crée la demande de prélèvement de chaque échéance réglée par prélèvement, quelques jours avant sa date (5 par défaut), à partir de son premier passage, jamais pour une facture en litige. Une échéance sans mode à elle suit le mode de la facture. Après un rejet, rien n'est redemandé : vous décidez.
- **Liste Facturation > Échéances** : toutes les échéances, filtres (état, type, mode, période, recherche), action de masse « Enregistrer les paiements » (un paiement par facture avec la référence du virement, jamais plus que le reste à payer de la facture, une facture dont un prélèvement est en cours sautée ; à faire après vérification du relevé) et « Facturer les échéances » (jamais une échéance à vérifier). Le même tableau dans un onglet de la fiche client.
- **Widget d'accueil** : les échéances en retard, à vérifier ou attendues ce mois-ci.
- **Modèles d'échéancier** dans Configuration > Dictionnaires : 3 fois, 30 % puis 70 % à 30 jours, 12 mensualités fournis. Un modèle peut avoir des **échéances libres** (`pourcentage:jours[:fdm]` séparées par `;`, par exemple `30:0;30:30;40:60:fdm` = 30 % à la date du document, 30 % à 30 jours, 40 % fin de mois à 60 jours) et une **condition de règlement** : une facture, un devis ou une commande validé avec cette condition et sans échéancier le reçoit.
- **Règle des 60 jours** (France, entre professionnels, article L441-10 du Code de commerce) : un avertissement quand un échéancier de paiement d'un client entreprise dépasse 60 jours après la date de la facture.
- **Reprise du module Échéancier** (Artis Auxilium) : ses échéanciers sont copiés depuis Configuration > Échéances > Reprise d'Échéancier, avec un aperçu chiffré ; ses tables sont seulement lues. Pour chaque échéance de devis ou de commande sans facture, la facture faite à la main est cherchée (même montant à 45 jours près) : **seule une facture liée au document est rattachée** ; une autre facture du client est seulement suggérée, dans la note de l'échéance. Les autres arrivent « À vérifier », passées ou futures : la tâche ne les facture jamais.
- **Une échéance à vérifier** n'est jamais facturée, ni par la tâche ni à la main : dans l'onglet du document, « Déjà facturée » (une facture du client, pas plus petite que l'échéance), « Abandonner », ou « Remettre à facturer » une fois vérifiée. Ces actions, et « Pas cette facture », demandent aussi le droit de créer des factures, et écrivent un événement dans l'agenda du document.
- **Un échéancier fait sur un brouillon** dont le total a changé est remis à l'échelle de la facture à sa validation (mêmes dates, mêmes parts).

## Avec les relances

La relance de Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) part à la date d'échéance de la facture : avec un échéancier, elle partirait à chaque échéance avec tout le reste à payer. Le module le signale. Le module gratuit [Relances](https://github.com/guiltekmdion/dolibarr-relances) (1.5.0 ou plus) relance chaque échéance échue pour son seul montant, et jamais une échéance dont le prélèvement est en cours.

## Astuce sans module

Pour les cas simples, Dolibarr sait déjà faire « un paiement attendu = une facture » : une condition de règlement avec acompte (facture d'acompte puis facture de solde depuis le devis ou la commande), ou une facture modèle (récurrente) pour des mensualités égales.

## Limites

- Les factures des échéances valent chacune leur échéance au centime (ce que paie le client) ; chacune arrondit sa propre TVA : leur TVA peut différer d'un ou deux centimes de celle du document.
- Le lien de paiement en ligne d'une facture propose tout le reste de la facture, pas l'échéance.
- Le module écrit la date d'échéance des factures ; désactivé, il ne le fait plus : la date reste celle du dernier paiement.
- La trésorerie prévisionnelle de Dolibarr et le transfert en comptabilité ne connaissent qu'une date d'échéance par facture (voir plus bas) ; la liste du module donne les échéances.
- Le widget et la liste lisent toutes les échéances à chaque affichage (sans souci pour quelques milliers).
- Deux passages de la tâche en même temps (la tâche et « Exécuter » à la main) ne sont pas verrouillés l'un contre l'autre.
- Le transfert en comptabilité copie la date d'échéance de la facture du moment (sa prochaine échéance impayée), comme pour toute facture : le logiciel comptable reçoit une date d'échéance par facture.
- Facture électronique (Factur-X) : une date d'échéance par facture, les échéances sont dans le texte du PDF.
- La trésorerie prévisionnelle passe par les hooks `addMoreSQL` et `moreFamily` de Dolibarr : si un autre module donne lui aussi une requête à ce hook, Dolibarr ne sait pas fusionner les deux et la page peut tomber en erreur ; si un autre module remplace le résultat du hook, les échéances ne sont pas listées, et les factures à échéancier non plus.
- La version 1.1.0 a changé le nom technique (`installments`) : désactiver et supprimer d'abord la 1.0.0. Le module payant « Payment Schedule » d'ATM ne peut pas servir en même temps sur les mêmes documents.

## Prérequis

Dolibarr 24.0 ou plus, avec les modules Factures clients et Travaux planifiés (Propositions commerciales, Commandes, Prélèvements et Banques si vous vous en servez). PHP 8.1 ou plus. MySQL/MariaDB ou PostgreSQL.

## Installation

Avant d'utiliser des échéanciers, **désactivez les tâches de relance de Dolibarr** (« Envoyer un rappel par email pour les factures validées et impayées ») : elles réclament tout le reste de la facture à chaque échéance. Utilisez plutôt le module Relances.

1. Télécharger `module_installments-X.Y.Z.zip` dans les [versions publiées](https://github.com/guiltekmdion/dolibarr-echeances/releases).
2. Dans Dolibarr : Accueil > Configuration > Modules > Déployer/Installer un module externe, puis envoyer le zip. Ou le décompresser dans `htdocs/custom` (le dossier `installments` doit être en `htdocs/custom/installments`).
3. Activer le module **Échéances** (dans Modules financiers), puis donner les droits aux utilisateurs.

Après une mise à jour du module, le désactiver puis le réactiver : **obligatoire**. Les dates des premiers passages des tâches sont gardées (depuis la 1.2.0).

## Configuration

1. Les modèles d'échéancier : Accueil > Configuration > Dictionnaires > Modèles d'échéancier (nombre, mois et jours entre deux échéances, part de la première, code du mode de règlement ; ou échéances libres ; code de la condition de règlement qui le donne).
2. Pour facturer et prélever automatiquement : activer les tâches « Factures des échéances » et « Prélèvements des échéances » dans Accueil > Outils d'administration > Travaux planifiés. **Premier passage de chaque tâche** : il ne crée rien pour les échéances antérieures. Elles restent à facturer, ou à prélever, à la main.
3. Prélèvements : Dolibarr garde une seule demande en attente par facture ; mettre `WITHDRAW_STRICT_CHECK_AMOUNT` à 1 pour en autoriser plusieurs.

Droits : « Voir les échéanciers », « Créer et modifier les échéanciers, facturer leurs échéances » (avec les droits des factures, devis et commandes : la liste montre les échéances des documents qu'on peut lire). Rattacher, abandonner ou délier une échéance demande aussi le droit de créer des factures. Enregistrer des paiements demande le droit de saisir les règlements des factures.

## Support

Questions et bogues : [tickets](https://github.com/guiltekmdion/dolibarr-echeances/issues). Développements sur mesure : [Guiltek](https://www.guiltek.fr).

## Licence

Copyright (C) 2026 Maxime DION, Guiltek.
Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le modifier selon les termes de la GNU General Public License publiée par la Free Software Foundation, version 3 ou (à votre choix) toute version ultérieure. Voir [COPYING](COPYING).
