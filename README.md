start the server at:
php -S localhost:8888

Using Postman:
1. Create a User
- "http://bilemopdo.local/v1/users" POST
2. Create a session
- "http://bilemopdo.local/v1/sessions" POST
3. Copy the access token and put it in the Headers with 
the key Authorization
4. Create a customer
- "http://bilemopdo.local/v1/customers" POST
5. Try to change the date of access token to expire token.
6. Refresh the expired token
- in headers put the expired token
- provide the refresh token in the body
- "http://bilemopdo.local/v1/sessions/5" PATCH
7. Use the new token in headers 
8. Delete a user that belongs to you with the id in the end
"http://bilemopdo.local/v1/customers/11" DELETE
9. Logout user with id session provided
"http://bilemopdo.local/v1/sessions/5" DELETE


