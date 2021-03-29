create, delete, update DNS records from command line for Cloudflare <br />

usage:<br />
#delete domain example.com <br />
    `php cloudflare.php -D --domain 'example.com'` <br />
  
#create domain example.com <br />
    `php cloudflare.php -C --domain 'example.com'` <br />
  
#delete dns record www.example.com <br />
    `php cloudflare.php -D --domain 'example.com' --name 'www'` <br />
  
#create dns record www1.example.com <br />
    `php cloudflare.php -C --domain example.com --name www1` <br />
