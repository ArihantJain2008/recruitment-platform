fetch("http://localhost/backend/auth/login.php",{
    method:'POST',
    headers:{"Content-Type":"application/json"},
    body:JSON.stringify({
        email:"test@gmail.com",
        password:"123456"
    })
})
.then(res => res.json())
.then(data => console.log(data));
