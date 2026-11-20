<?php
/**
 * TRQ – configuration par défaut.
 * Pour modifier un réglage, copiez la ligne dans un fichier « config.local.php » (même format, `return [...]`) :
 * il n'est jamais écrasé lors d'une mise à jour.
 */
return [
    // true  = connexion obligatoire, une base de données par utilisateur (mode en ligne)
    // false = mode local mono-utilisateur, sans connexion (comme avant)
    'auth'          => true,

    // Inscription : 'open' (ouverte), 'invite' (code d'invitation requis) ou 'closed' (fermée).
    // Le tout premier compte (administrateur) peut toujours être créé.
    'registration'  => 'open',
    'invite_code'   => '',

    // URL publique de l'app, utilisée dans les e-mails (ex. 'https://trq.exemple.fr'). Détectée automatiquement si vide.
    'base_url'      => '',
    // Adresse expéditrice des e-mails (réinitialisation du mot de passe). Ex. 'no-reply@exemple.fr'
    'mail_from'     => '',
    // null = automatique (activé seulement sur localhost) ; true = affiche le lien de réinitialisation à l'écran (dev uniquement !)
    'dev_mail'      => null,

    // Derrière un reverse proxy (Cloudflare, nginx…) : lire l'IP et le schéma dans X-Forwarded-*
    'trust_proxy'   => false,

    'min_password'  => 10,    // longueur minimale du mot de passe
    'session_days'  => 30,    // durée de « Rester connecté »
];
