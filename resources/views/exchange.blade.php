<!DOCTYPE html>
<html>
<head><title>Exchange Token</title></head>
<body>
  <script>
    // Extract code from URL
    const params = new URLSearchParams(window.location.search);
    const code = params.get("code");
    const code_verifier = localStorage.getItem("code_verifier");

    if (!code) {
      alert("Authorization code missing");
    } else {
      console.log("Got code:", code);
      // Send to Laravel backend
      fetch("http://localhost:8000/auth/token", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          code,
          code_verifier
        }),
      })
      .then(res => res.json())
      .then(data => {
        console.log("Access token response:", data);
        alert("Access Token retrieved! Check console.");
      })
      .catch(err => console.error(err));
    }
  </script>
</body>
</html>
