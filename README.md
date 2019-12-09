# mywifi

This project is a proof of conecpt and will let your users see their wifi info from Aruba Clearpass by logging into active directory with their credentials.  Ideally, the concept here could be integrated directly into Clearpass (Guest) without needing an external service to query the Clearpass database.

## Download

```bash
git clone https://github.com/mzac/mywifi.git mywifi
```

## Config

Edit the `config/config.php` file and fill in the your information

## Build

You will need to build the docker image once you have chnaged the config:

```bash
docker build -t mywifi:latest .
```

## Run

```bash
docker run -d -p 8080:8080 --name mywifi mywifi:latest
```
