# ThinkingHome-DynDns
 Dynamic DNS solution for ThinkingHome. Works with PHP and cPanel.
 
 Make request in every hour to update cPanel DNS zone if it changed. Get IP address form http request, so not needed to pass this.
 
 usage: https://dyn.domain.tld/update?token=usertoken
 
 The usertoken is NOT cPanel token! This is an internal token for your users.
