# Global Site Selector

The Global Site Selector allows you to run multiple small Nextcloud instances and redirect users to the right server.

It can run in two modes. In "master" mode the server will query the lookup-server for the users location and redirect the user to the right Nextcloud server. In "slave" mode the server will be able to receive and handle the redirects of the master.

This app will always use the same protocol (HTTP or HTTPS) to do the redirect to the slave as what is used to connect to the master node. That means if the master is accessed via HTTPS the login redirect will also happen via HTTPS.

## Configuration

To use the Global Site Connector you need to add some config parameters to the config.php

### Master

Config.php parameters to operate the server in master mode:

````
// can be chosen freely, you just have to make sure the master and
// all slaves have the same key.  Also make sure to choose a strong shared secret.
'gss.jwt.key' => 'random-key',

// operation mode
'gss.mode' => 'master',

// define a master admins, this users will not be redirected to a slave but are
// allowed to login at the master node to perform administration tasks
'gss.master.admin' => ['admin1Uid', 'admin2Uid'],

// define a parameter given by the IdP to decide where a user is located
'gss.saml.slave.mapping' => 'idpParameterSlave',
````

### Slave

Config parameters to operate the server in slave mode:

````
// can be chosen freely, you just have to make sure the master and
// all slaves have the same key. Also make sure to choose a strong shared secret.
'gss.jwt.key' => 'random-key',

// operation mode
'gss.mode' => 'slave',

// url of the master, so we can redirect the user back in case of an error
'gss.master.url' => 'http://localhost/nextcloud2',
````

