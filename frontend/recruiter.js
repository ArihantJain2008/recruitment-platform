// Get current user
const user = JSON.parse(localStorage.getItem("user"));
if (!user || user.role !== "recruiter") {
  window.location.href = "/recruitment-platform/frontend/login.html";
}

let allJobs = [];
let selectedJobId = null;

function loadJobs() {
  fetch("http://localhost/recruitment-platform/backend/jobs/list.php")
    .then(res => res.json())
    .then(jobs => {
      allJobs = jobs;
      displayJobs(jobs);
      updateJobSelect(jobs);
      updateStats(jobs);
    })
    .catch(err => {
      console.error("Error loading jobs:", err);
    });
}

function displayJobs(jobs) {
  const container = document.getElementById("jobs-list");
  
  if (!jobs || jobs.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No jobs yet</h3>
        <p>Create your first job posting to get started.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = jobs.map(job => `
    <div class="card job-card">
      <div style="flex: 1;">
        <h3>${escapeHtml(job.title)}</h3>
        ${job.description ? `<p style="color: #64748b; margin: 8px 0;">${escapeHtml(job.description)}</p>` : ''}
        <div style="display: flex; gap: 16px; margin-top: 12px; flex-wrap: wrap;">
          ${job.skills_required ? `<div><strong>Skills:</strong> ${escapeHtml(job.skills_required)}</div>` : ''}
          ${job.experience_required ? `<div><strong>Experience:</strong> ${escapeHtml(job.experience_required)}</div>` : ''}
        </div>
      </div>
      <div style="display: flex; gap: 8px; align-items: flex-start;">
        <button class="btn-primary" onclick="editJob(${job.id})" style="margin: 0;">Edit</button>
        <button class="btn-danger" onclick="deleteJob(${job.id})" style="margin: 0;">Delete</button>
      </div>
    </div>
  `).join('');
}

function updateJobSelect(jobs) {
  const select = document.getElementById("job-select");
  select.innerHTML = '<option value="">Select a job...</option>' + 
    jobs.map(job => `<option value="${job.id}">${escapeHtml(job.title)}</option>`).join('');
}

function updateStats(jobs) {
  document.getElementById("stat-jobs").innerText = jobs.length;
  
  // Load all applications to calculate stats
  Promise.all(jobs.map(job => 
    fetch(`http://localhost/recruitment-platform/backend/applications/list.php?job_id=${job.id}`)
      .then(res => res.json())
  )).then(allApplicants => {
    const applicants = allApplicants.flat();
    document.getElementById("stat-applicants").innerText = applicants.length;
    
    let shortlisted = 0;
    let rejected = 0;
    let interviewed = 0;
    
    applicants.forEach(app => {
      if (app.status === "shortlisted") shortlisted++;
      if (app.status === "rejected") rejected++;
      if (app.status === "interviewed") interviewed++;
    });
    
    document.getElementById("stat-shortlisted").innerText = shortlisted;
    document.getElementById("stat-rejected").innerText = rejected;
    document.getElementById("stat-interviewed").innerText = interviewed;
  });
}

function loadApplicants() {
  const select = document.getElementById("job-select");
  const jobId = select.value;
  selectedJobId = jobId;

  console.log("Selected Job ID:", jobId);

  const div = document.getElementById("applicants");

  if (!jobId) {
    div.innerHTML = "<p class='empty-state'>Please select a job.</p>";
    return;
  }

  fetch(`http://localhost/recruitment-platform/backend/applications/list.php?job_id=${jobId}`)
    .then(res => res.json())
    .then(data => {
      if (data.length === 0) {
        div.innerHTML = "<p class='empty-state'>No applicants yet for this job.</p>";
        return;
      }

      div.innerHTML = data.map(app => `
        <div class="card">
          <h3>${escapeHtml(app.name)}</h3>
          <p>${escapeHtml(app.email)}</p>
          <p>Score: ${app.score ?? 'N/A'}</p>
          <p>Status: <span class="status ${app.status}">${app.status.toUpperCase()}</span></p>

          <button class="btn-primary" onclick="updateStatus(${app.application_id}, 'shortlisted')">
            Shortlist
          </button>

          <button class="btn-secondary" onclick="updateStatus(${app.application_id}, 'interviewed')">
            Interview
          </button>

          <button class="btn-danger" onclick="updateStatus(${app.application_id}, 'rejected')">
            Reject
          </button>
        </div>
      `).join('');
    })
    .catch(err => {
      div.innerHTML = "<p class='empty-state'>Error loading applicants.</p>";
    });
}

function showJobForm(jobId = null) {
  const modal = document.getElementById("job-form-modal");
  const formTitle = document.getElementById("job-form-title");
  const form = document.getElementById("job-form");
  
  if (jobId) {
    formTitle.textContent = "Edit Job";
    const job = allJobs.find(j => j.id == jobId);
    if (job) {
      document.getElementById("job-id").value = job.id;
      document.getElementById("job-title").value = job.title || '';
      document.getElementById("job-description").value = job.description || '';
      document.getElementById("job-skills").value = job.skills_required || '';
      document.getElementById("job-experience").value = job.experience_required || '';
    }
  } else {
    formTitle.textContent = "Create New Job";
    form.reset();
    document.getElementById("job-id").value = '';
  }
  
  modal.style.display = "flex";
}

function closeJobForm() {
  document.getElementById("job-form-modal").style.display = "none";
  document.getElementById("job-form").reset();
  document.getElementById("job-id").value = '';
}

function saveJob() {
  const jobId = document.getElementById("job-id").value;
  const title = document.getElementById("job-title").value.trim();
  const description = document.getElementById("job-description").value.trim();
  const skills = document.getElementById("job-skills").value.trim();
  const experience = document.getElementById("job-experience").value.trim();

  if (!title) {
    alert("Job title is required");
    return;
  }

  const url = jobId 
    ? "http://localhost/recruitment-platform/backend/jobs/update.php"
    : "http://localhost/recruitment-platform/backend/jobs/create.php";

  const body = jobId
    ? { id: parseInt(jobId), title, description, skills_required: skills, experience_required: experience }
    : { recruiter_id: user.id, title, description, skills_required: skills, experience_required: experience };

  fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body)
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        closeJobForm();
        loadJobs();
        if (selectedJobId) {
          loadApplicants();
        }
      } else {
        alert("Failed to save job: " + (data.error || "Unknown error"));
      }
    })
    .catch(() => {
      alert("Failed to save job. Please try again.");
    });
}

function editJob(jobId) {
  showJobForm(jobId);
}

function deleteJob(jobId) {
  if (!confirm("Are you sure you want to delete this job? This action cannot be undone.")) {
    return;
  }

  fetch("http://localhost/recruitment-platform/backend/jobs/delete.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: jobId })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        loadJobs();
        document.getElementById("job-select").value = "";
        document.getElementById("applicants").innerHTML = "<p class='empty-state'>Please select a job to view applicants.</p>";
      } else {
        alert("Failed to delete job: " + (data.error || "Unknown error"));
      }
    })
    .catch(() => {
      alert("Failed to delete job. Please try again.");
    });
}

function updateStatus(id, status) {
  fetch("http://localhost/recruitment-platform/backend/applications/update_status.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ application_id: id, status })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        loadApplicants();
        loadJobs(); // Refresh stats
      } else {
        alert("Failed to update status: " + (data.error || "Unknown error"));
      }
    })
    .catch(() => {
      alert("Failed to update status. Please try again.");
    });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById("job-form-modal");
  if (event.target == modal) {
    closeJobForm();
  }
}
document.addEventListener("DOMContentLoaded", () => {
  loadJobs();
});
