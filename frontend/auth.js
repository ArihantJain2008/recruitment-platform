function register() {
  const name = document.getElementById("name").value.trim();
  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;
  const role = document.querySelector('input[name="role"]:checked').value;

  fetch("http://localhost/recruitment-platform/backend/auth/register.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ name, email, password, role })
  })
    .then(res => res.json())
    .then(data => {
      alert(data.message || data.error);
      if (!data.error) {
        window.location.href = "/recruitment-platform/frontend/login.html";
      }
    });
}

function login() {
  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;

  fetch("http://localhost/recruitment-platform/backend/auth/login.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password })
  })
    .then(res => res.json())
    .then(data => {
      if (!data.error) {
        localStorage.setItem("user", JSON.stringify(data));

        if (data.role === "recruiter") {
          window.location.href = "/recruitment-platform/frontend/recruiter.html";
        } else if (data.role === "candidate") {
          window.location.href = "/recruitment-platform/frontend/candidate.html";
        }
      } else {
        alert(data.error);
      }
    });
}

function logout() {
  localStorage.removeItem("user");
  window.location.href = "/recruitment-platform/frontend/login.html";
}
