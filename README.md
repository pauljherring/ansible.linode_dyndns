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
- That account should be in control of DNS entries for a (base) FQDN you own
- PHP installed on the machine to run the script.

Role Variables
--------------

`defaults/main.yaml`

- `linode_dyndns_domain`: The dns entry (FQDN) that you control (e.g. `example.com`)
- `linode_dyndns_hostname`: The unique subdomain name of this host (without any additional prefixes and without the domain, e.g. `desktop` in `desktop.example.com`)
- `linode_dyndns_token`: the 64-character API token obtained from Linode with access to modify DNS settings. Should normally be in a `*.enc` file.
- `linode_dyndns_vpn_gateway`: If using `vpn` as a discovery method for the IP address of a VPN IP, the ip address of the remote gateway (or another host on that VPN). Defaults to `172.20.0.0`.
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
  - Copy, and don't lose the `Personal Access Token` displayed - this is what's needed for dyndns - `linode_dyndns_token` above.

Dependencies
------------

None

Example Playbook
----------------

Link `host_vars` for all subdomains to common host file

```bash
$ ls -l host_vars/*hpnotebook*
-rw-rw-r-- 1 pherring pherring 54 Dec  8 11:20 host_vars/hpnotebook.example.co.uk.yaml
lrwxrwxrwx 1 pherring pherring 29 Dec  7 16:15 host_vars/lan.hpnotebook.example.co.uk.yaml -> hpnotebook.example.co.uk.yaml
lrwxrwxrwx 1 pherring pherring 29 Dec 24 18:32 host_vars/pub.hpnotebook.example.co.uk.yaml -> hpnotebook.example.co.uk.yaml
lrwxrwxrwx 1 pherring pherring 29 Dec  7 16:15 host_vars/vpn.hpnotebook.example.co.uk.yaml -> hpnotebook.example.co.uk.yaml
```

And set that one...

```bash
$ cat host_vars/hpnotebook.example.co.uk.yaml
hostname: hpnotebook
linode_dyndns_default_method: lan
```

`inventory.yaml`
---

```yaml
all:
  hosts:
  children:
    test:
      hosts:
        vpn.hpnotebook.example.co.uk:
```

`all.yaml`
---

```yaml
- name: Config for all my computers
  hosts: test
  gather_facts: yes
  vars:
    linode_dyndns_domain: example.co.uk
    linode_dyndns_hostname: "{{hostname}}"

  tasks:
    - name: Configure linode-dyndns
      import_role:
        name: ansible.linode_dyndns

```

`secrets.enc`
---

```yaml
#linode_dyndns
linode_dyndns_token: bc7db5d741ff5cbb1339aa66e51148c33d2ac408d1daa17bdc26424600a0ac3f
```

Command line
---

```bash
ansible-playbook -K all.yaml -i inventory.yaml -e @secrets_file.enc --ask-vault-pass --diff
```

License
-------

MIT

Author Information
------------------
