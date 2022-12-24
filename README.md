linode_dyndns
=========

An implementation of 'dyndns' in conjuction with Linode.

This will provide dns for the following hostnames:

- `example.com` - the 'default' host, whose IP may be configured to the ideal one (see `linode_dyndns_default_method`) - specifically one of the following:
- `vpn.example.com` - the IP address of a vpn interface, if any.
- `lan.example.com` - the IP address of a local lan.
- `pub.example.com` - the IP address the host appears as by servers on the internet.

Requirements
------------

- An account with Linode
- That account controlling DNS entries for a website you own
- PHP installed on the machine this is to be run on.

Role Variables
--------------

A description of the settable variables for this role should go here, including any variables that are in defaults/main.yml, vars/main.yml, and any variables that can/should be set via parameters to the role. Any variables that are read from other roles and/or the global scope (ie. hostvars, group vars, etc.) should be mentioned here as well.
`defaults/main.yaml`

- `linode_dyndns_domain`: The dns entry that you control (e.g. `example.com`)
- `linode_dyndns_hostname`: The unique subdomaine name of this host (without any additional prefixes, e.g. `desktop.example.com`)
- `linode_dyndns_token`: the 64-character API token obtained from Linode with access to modify DNS settings. Should normally be in a `*.enc` file.
- `linode_dyndns_vpn_gateway`: If using `vpn` as a discovery method for the IP address of a VPN IP, the ip address of the remote gateway. Defaults to `172.20.0.0`.
- `linode_dyndns_default_method`: Which discovery method to apply to the default full hostname of `linode_dyndns_hostname.linode_dyndns_domain`. Defaults to `ipv.me`. Options available:
  - `ipv.me` - use an external service to determine the 'public IP' of the host
  - `vpn` - identify the IP of the internal interface connecting to a VPN (see `linode_dyndns_vpn_gateway`)
  - `local` - the internal IP of the interface that reaches out to the internet


Linode setup
------------

It is suggested you create another Linode role/user for this. At the time of writing:

- Log in to your account
- Go to `[Users & Grants](https://cloud.linode.com/account/users)`
- `Add a User`
- Input a new username/email, switch off `This user will have full access to account features.` You can use an existing in-use email for this.
- (While awaiting email for password setup...)
- On the [next screen](https://cloud.linode.com/account/users/[username-from-above]/permissions):
  - Enable `Can add Domains using the DNS Manager` only.
  - No billing access required
  - No linode-specific permissions required
  - R/W access only to the domain you'll be using for this
  - No longview Client access required
  - Anything else not mentioned here, unlikely to need any access required

When you receive the email for setting the password

- open the URL in a private window
- set the password
- log into that account
- Go to Account > [API Tokens](https://cloud.linode.com/profile/tokens)
- `Create a Personal Access Token`
  - Lable: `whatever`
  - Expiry: Never (probably? - you can delete them later if necessary)
  - Only thing that needs to be Read/Write is Domains, the rest can (probably should) be 'None'
  - Copy, and don't lose the `Personal Access Token` displayed - this is what's needed for dyndns.

Dependencies
------------

A list of other roles hosted on Galaxy should go here, plus any details in regards to parameters that may need to be set for other roles, or variables that are used from other roles.

Example Playbook
----------------

Including an example of how to use your role (for instance, with variables passed in as parameters) is always nice for users too:

    - hosts: servers
      roles:
         - { role: username.rolename, x: 42 }

License
-------

MIT

Author Information
------------------
