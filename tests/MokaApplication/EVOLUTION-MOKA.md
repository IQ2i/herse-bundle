# Faire évoluer MokaCMS pour Herse : proposition

Document de travail pour la discussion avec le mainteneur de MokaCMS. Il décrit une troisième approche, à côté des
deux déjà prototypées dans ce dépôt, et ce qu'elle changerait.

## Les trois approches

| | Où vit le code d'intégration | État |
|---|---|---|
| 1. Tout dans le projet | chaque projet Moka (9 fichiers, environ 390 lignes) | prototypé, branche `test/moka-application` |
| 2. Pont dans Herse | `src/Bridge/Moka` du bundle (environ 690 lignes) | prototypé, branche `feat/moka-bridge` |
| 3. Évolution de Moka | une version mineure de `moka/cms` | **proposition, rien n'est écrit** |

Les approches 1 et 2 fonctionnent sans toucher à Moka, mais au prix de contournements. L'approche 3 supprime ces
contournements à la source ; elle se combine avec 1 ou 2, elle ne les remplace pas forcément.

## Ce qui oblige à contourner Moka aujourd'hui

Un seul vrai blocage, et un manque secondaire.

**La classe User générée n'a aucun point d'extension.** `UserClassDumper::dump()` construit la classe et l'écrit
dans le cache en une fois (`src/Generator/ClassDumper/UserClassDumper.php`), et `UserMapper` fixe ses colonnes
(`src/Bridge/Doctrine/Mapping/Mapper/UserMapper.php`). `moka_cms.user.fields` ajoute des champs à widget, pas une
interface ni une colonne technique. Or la 2FA de scheb exige une interface sur l'objet utilisateur. Conséquences :

- approche 1 : le projet redéfinit deux services de Moka sous leur propre identifiant et recopie le corps de
  `dump()` ;
- approche 2 : le bundle change la classe de ces deux services par une passe de compilation et réécrit le fichier
  généré avec une expression régulière.

Les deux tiennent tant que Moka ne bouge pas, mais reposent sur des détails internes.

**Le mot de passe en clair n'est porté par aucune classe.** `ResetPasswordType` n'a pas de `data_class`
(`src/Form/Type/ResetPasswordType.php`) et `moka:create-user` hache le mot de passe avant de valider l'entité
(`src/Command/CreateUserCommand.php:63-66`). La politique de mot de passe passe donc par une extension de formulaire,
et la commande reste hors de portée.

## L'évolution proposée

### A. Un point d'extension sur la classe User (l'essentiel)

Une interface que Moka appelle aux deux endroits où il fabrique le User, alimentée par un tag de service, comme Moka
le fait déjà pour `moka_cms.doctrine_mapper` ou `moka_cms.generator.class.dumper`.

```php
namespace Moka\Cms\Generator;

interface UserExtensionInterface
{
    // Appelé par UserClassDumper::dump() avant l'écriture du fichier.
    public function configureClass(ClassGenerator $class): void;

    // Appelé par UserMapper::loadMetadataForClass() après les colonnes de Moka.
    public function loadMetadata(ClassMetadataBuilder $builder): void;
}
```

Côté Moka : l'interface, son autoconfiguration avec un tag `moka_cms.user_extension`, un itérateur injecté dans
`UserClassDumper` et `UserMapper`, et deux boucles. `ClassGenerator` expose déjà `addImplement()` et `addTrait()`.
Ordre de grandeur : une trentaine de lignes et un test, sans changement de comportement pour les projets existants.

Ce que ça permet, côté Herse ou côté projet :

```php
final class TwoFactorUserExtension implements UserExtensionInterface
{
    public function configureClass(ClassGenerator $class): void
    {
        $class->addImplement(TwoFactorInterface::class);
        $class->addTrait(EmailTwoFactorTrait::class);
    }

    public function loadMetadata(ClassMetadataBuilder $builder): void
    {
        $builder->createField('emailAuthEnabled', 'boolean')->option('default', false)->build();
        $builder->addField('authCode', 'string', ['nullable' => true]);
    }
}
```

Variante écartée : des clés `moka_cms.user.interfaces` / `traits` dans la configuration. Elle règle la classe mais
pas le mapping des colonnes, puisque le driver de Moka ne lit pas les attributs Doctrine d'un trait.

### B. Un modèle pour le mot de passe en clair (secondaire)

Une petite classe `Moka\Cms\Form\Model\ResetPassword` avec une propriété `password`, utilisée comme `data_class` de
`ResetPasswordType` et validée par `moka:create-user` avant le hachage. La politique de Herse se pose alors sur cette
classe par un simple mapping de validation, exactement comme pour les modèles de Sylius (`docs/sylius.md`), et
couvre du même coup la commande. Ordre de grandeur : une vingtaine de lignes.

### C. Variante plus ambitieuse : héberger le pont dans Moka

Plutôt que dans Herse, le code du pont (écran des appareils, écran du code 2FA, interrupteur dans la fiche
utilisateur, valeurs par défaut) pourrait vivre dans Moka, activé seulement si Herse est installé. Avantages : rien
de Moka n'apparaît dans le dépôt public de Herse, et le pont devient testable dans la CI de Moka. Inconvénient :
c'est du développement fonctionnel dans un CMS qu'on ne veut plus faire évoluer, et Herse exige Symfony 6.4 et
PHP 8.2 alors que Moka accepte encore Symfony 5.4 et PHP 8.1, donc tout doit rester optionnel.

## Ce que ça change pour chaque approche

| | Sans évolution | Avec A | Avec A + B |
|---|---|---|---|
| Approche 1 (projet) | 2 services redéfinis, `dump()` recopié | une classe d'extension, plus aucun service redéfini | idem, et l'extension de formulaire devient un mapping |
| Approche 2 (pont dans Herse) | passe de compilation + réécriture du fichier généré | les 3 classes concernées (environ 130 lignes) remplacées par une classe d'une trentaine de lignes | idem, moins l'extension de formulaire (environ 50 lignes) |
| `moka:create-user` et politique | non couvert | non couvert | couvert |

Ce qui ne change pas, quelle que soit l'évolution : les trois ajouts dans `security.yaml` (Symfony ne laisse pas un
bundle compléter un pare-feu), l'import des routes, et la contrainte Symfony 6.4 / PHP 8.2 ou 8.3.

## À profiter de la même version

Trois faiblesses de Moka relevées pendant l'étude, que Herse ne corrige pas :

- `isActive` n'est vérifié par aucun `UserChecker` : un compte désactivé peut encore se connecter ;
- le jeton de réinitialisation est stocké en clair et comparé avec `===` (`src/Security/ResetTokenHelper.php:30-32`) ;
- la case « se souvenir de moi » est affichée et l'authenticator active le badge, mais sans `remember_me` sur le
  pare-feu elle n'a aucun effet.

## Recommandation

Demander **A** en priorité : c'est le seul changement qui supprime un contournement fragile, il est petit, sans
risque pour les projets existants, et il sert aux approches 1 comme 2. **B** est un confort à prendre si la version
est ouverte de toute façon. **C** ne vaut la discussion que si l'on tient à ne rien exposer de Moka dans le dépôt
public de Herse et que le mainteneur accepte d'y porter du code fonctionnel.

## Questions pour le mainteneur

1. Une version mineure (1.2.0) est-elle envisageable sur un projet déclaré figé, et sous quel délai ?
2. Le tag `moka_cms.user_extension` convient-il, ou préfère-t-il un événement sur le modèle de `moka.model.load` ?
3. Les projets existants pourront-ils monter sur cette version sans autre changement (Symfony 5.4 compris) ?
4. Les trois correctifs de sécurité peuvent-ils partir dans la même version ?
