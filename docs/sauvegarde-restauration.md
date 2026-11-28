\# Sauvegarde et restauration de la base de données SwimPro



\## 1. Présentation



Ce document explique comment sauvegarder et restaurer la base de données MySQL du projet SwimPro avec Docker Compose.



\## 2. Prérequis



\- Docker Desktop doit être démarré.

\- Le projet SwimPro doit être ouvert dans un terminal.

\- Les services Docker doivent être lancés.



Pour démarrer les services :



```bash

docker compose up -d

```



\## 3. Sauvegarde de la base de données



Pour créer une sauvegarde, exécuter :



```bash

./scripts/backup\_db.sh

```



Le fichier SQL est créé automatiquement dans le dossier `backups/`.



\## 4. Restauration de la base de données



Pour restaurer une sauvegarde, exécuter :



```bash

./scripts/restore\_db.sh backups/nom\_du\_fichier.sql

```



Le script demande une confirmation avant de restaurer les données.



\*\*Attention :\*\* la restauration peut remplacer les données actuelles de la base.



\## 5. Configuration



Les paramètres de connexion MySQL sont récupérés depuis les variables d'environnement du fichier `docker-compose.yml`.



\## 6. Protection des sauvegardes



Les fichiers SQL de sauvegarde sont ignorés par Git grâce à la règle :



```gitignore

backups/\*.sql

```
