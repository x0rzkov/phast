RUN = docker run -it -v $(shell pwd):/data -w /data $(shell cat .docker-image-id)


.PHONY : all test test-local update docker


all : vendor/autoload.php

test : all docker
	$(RUN) vendor/bin/phpunit

test-local : all
	vendor/bin/phpunit

update : all
	vendor/composer.phar update

docker : .docker-image-id


vendor/autoload.php : vendor/composer.phar composer.json composer.lock
	vendor/composer.phar install

vendor/composer.phar :
	mkdir -p vendor
	wget -O $@~ https://github.com/composer/composer/releases/download/1.5.2/composer.phar
	chmod +x $@~
	mv $@~ $@

.docker-image-id : Dockerfile docker/entrypoint
	docker build -q . > $@~
	mv $@~ $@
