# Échéances pour Dolibarr : payer une facture en plusieurs fois, facturer un devis en plusieurs fois

Module gratuit fait par [Guiltek](https://www.guiltek.fr), sous licence GPL v3 ou plus. English version: [README.md](README.md).

Dans Dolibarr, une facture n'a qu'une date d'échéance et un mode de règlement. Ce module ajoute un **échéancier** : plusieurs échéances, chacune avec sa date, son montant et son mode de règlement. Il sert à deux choses :

- **une facture attendue en plusieurs paiements** (30 % à la commande et 70 % à 30 jours, 3 prélèvements...) ;
- **un devis ou une commande facturé en plusieurs fois** : chaque échéance devient une facture le jour venu, créée par une tâche planifiée.

## Ce qu'il fait

- **Onglet Échéancier** sur les factures, devis et commandes : les échéances (date, montant, mode, déjà réglé, reste, état à venir / en retard / payée / facturée), création avec un modèle ou « en N fois » (nombre, mois et jours entre deux échéances, part de la première), modification ligne par ligne, aperçu avant enregistrement. Une échéance payée, facturée ou prélevée ne peut plus changer.
- **Ce qui est payé** n'est jamais saisi à la main : les paiements, avoirs et acomptes de la facture sont donnés aux échéances par date, la plus ancienne d'abord.
- **Date d'échéance de la facture = prochaine échéance impayée**, recalculée à chaque paiement : les listes « en retard », les widgets, la relance de Dolibarr et le PDF de Dolibarr suivent les échéances, sans rien changer au cœur. La trésorerie prévisionnelle de Dolibarr prend cette date, mais pour tout le reste à payer de la facture.
- **PDF de la facture** (modèle sponge) : le tableau des échéances sous les totaux (au-delà de 10 échéances, celles qui restent à payer et une ligne pour les dernières). Clé `__PAYMENTSCHEDULE__` (une échéance par ligne, en HTML) pour la note publique, les modèles d'e-mail et les documents ODT.
- **Une facture par échéance** (devis et commandes) : la tâche « Factures des échéances » crée la facture de chaque échéance à sa date, au prorata de chaque taux de TVA du document (la dernière échéance prend le reste de chaque ligne). Réglage par échéancier : brouillon, validée, ou validée et envoyée par e-mail (modèle choisi, contact de facturation sinon e-mail du client, PDF joint). Bouton « Facturer maintenant » et action de masse pour le faire à la main.
- **Prélèvements** : la tâche « Prélèvements des échéances » crée la demande de prélèvement de chaque échéance réglée par prélèvement, quelques jours avant sa date (5 par défaut).
- **Liste Facturation > Échéances** : toutes les échéances, filtres (état, type, mode, période, recherche), action de masse « Enregistrer les paiements » (un paiement par facture, à faire après vérification du relevé) et « Facturer les échéances ». Le même tableau dans un onglet de la fiche client.
- **Widget d'accueil** : les échéances en retard ou attendues ce mois-ci.
- **Modèles d'échéancier** dans Configuration > Dictionnaires : 3 fois, 30 % puis 70 % à 30 jours, 12 mensualités fournis.
- **Règle des 60 jours** (France, entre professionnels, article L441-10 du Code de commerce) : un avertissement quand un échéancier de paiement d'un client entreprise dépasse 60 jours après la date de la facture.
- **Reprise du module Échéancier** (Artis Auxilium) : ses échéanciers sont copiés depuis Configuration > Échéances > Reprise d'Échéancier, avec un aperçu chiffré ; ses tables sont seulement lues. Les échéances passées de devis et commandes sans facture arrivent « À vérifier » : elles ont peut-être été facturées à la main, la tâche ne les facture jamais.

## Avec les relances

La relance de Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) part à la date d'échéance de la facture : avec un échéancier, elle partirait à chaque échéance avec tout le reste à payer. Le module le signale. Le module gratuit [Relances](https://github.com/guiltekmdion/dolibarr-relances) (1.4.0 ou plus) relance chaque échéance échue pour son seul montant.

## Astuce sans module

Pour les cas simples, Dolibarr sait déjà faire « un paiement attendu = une facture » : une condition de règlement avec acompte (facture d'acompte puis facture de solde depuis le devis ou la commande), ou une facture modèle (récurrente) pour des mensualités égales.

## Prérequis

Dolibarr 24.0 ou plus, avec les modules Factures clients et Travaux planifiés (Propositions commerciales, Commandes, Prélèvements et Banques si vous vous en servez). PHP 8.1 ou plus. MySQL/MariaDB ou PostgreSQL.

## Installation

1. Télécharger `module_paymentschedule-X.Y.Z.zip` dans les [versions publiées](https://github.com/guiltekmdion/dolibarr-echeances/releases).
2. Dans Dolibarr : Accueil > Configuration > Modules > Déployer/Installer un module externe, puis envoyer le zip. Ou le décompresser dans `htdocs/custom` (le dossier `paymentschedule` doit être en `htdocs/custom/paymentschedule`).
3. Activer le module **Échéances** (dans Modules financiers), puis donner les droits aux utilisateurs.

Après une mise à jour du module, le désactiver puis le réactiver : **obligatoire**.

## Configuration

1. Les modèles d'échéancier : Accueil > Configuration > Dictionnaires > Modèles d'échéancier (nombre, mois et jours entre deux échéances, part de la première, code du mode de règlement).
2. Pour facturer et prélever automatiquement : activer les tâches « Factures des échéances » et « Prélèvements des échéances » dans Accueil > Outils d'administration > Travaux planifiés. **Premier passage de la tâche des factures** : il ne crée rien pour les échéances antérieures. Elles restent à facturer à la main.
3. Prélèvements : Dolibarr garde une seule demande en attente par facture ; mettre `WITHDRAW_STRICT_CHECK_AMOUNT` à 1 pour en autoriser plusieurs.

Droits : « Voir les échéanciers », « Créer et modifier les échéanciers, facturer leurs échéances » (avec les droits des factures, devis et commandes). Enregistrer des paiements demande le droit de saisir les règlements des factures.

## Support

Questions et bogues : [tickets](https://github.com/guiltekmdion/dolibarr-echeances/issues). Développements sur mesure : [Guiltek](https://www.guiltek.fr).

## Licence

Copyright (C) 2026 Maxime DION, Guiltek.
Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le modifier selon les termes de la GNU General Public License publiée par la Free Software Foundation, version 3 ou (à votre choix) toute version ultérieure. Voir [COPYING](COPYING).
