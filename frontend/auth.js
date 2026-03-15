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
    .then(res => {
      return res.text().then(text => {
        try {
          const data = JSON.parse(text);
          if (!res.ok) {
            throw new Error(data.error || `HTTP error! status: ${res.status}`);
          }
          return data;
        } catch (e) {
          if (e.message && e.message.includes("HTTP error")) {
            throw e;
          }
          console.error("Invalid JSON response:", text);
          throw new Error("Server returned invalid JSON: " + text.substring(0, 100));
        }
      });
    })
    .then(data => {
      if (data.error) {
        alert("Error: " + data.error);
      } else {
        alert(data.message || "Registration successful!");
        window.location.href = "/recruitment-platform/frontend/login.html";
      }
    })
    .catch(error => {
      console.error("Registration error:", error);
      alert("Registration failed. Please check database connection.");
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
    .then(res => {
      return res.text().then(text => {
        try {
          const data = JSON.parse(text);
          if (!res.ok) {
            throw new Error(data.error || `HTTP error! status: ${res.status}`);
          }
          return data;
        } catch (e) {
          if (e.message && e.message.includes("HTTP error")) {
            throw e;
          }
          console.error("Invalid JSON response:", text);
          throw new Error("Server returned invalid JSON: " + text.substring(0, 100));
        }
      });
    })
    .then(data => {
      if (data.error) {
        alert("Error: " + data.error);
      } else {
        localStorage.setItem("user", JSON.stringify(data));

        if (data.role === "recruiter") {
          window.location.href = "/recruitment-platform/frontend/recruiter.html";
        } else if (data.role === "candidate") {
          window.location.href = "/recruitment-platform/frontend/candidate.html";
        }
      }
    })
    .catch(error => {
      console.error("Login error:", error);
      alert("Login failed. Please check database connection.");
    });
}

function logout() {
  localStorage.removeItem("user");
  window.location.href = "/recruitment-platform/frontend/login.html";
}
