document.addEventListener("wpcf7mailsent", function (event) {
  fetch(rekurencja_vars.ajax_url, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    },
    body: new URLSearchParams({
      action: "regenerate_token",
    }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error("Network response was not ok");
      }
      return response.json();
    })
    .then((data) => {
      if (data.token) {
        let tokenElement = document.querySelector('input[name="form_token"]');
        if (tokenElement) {
          //   console.error("Token ajax regenerated: " + data.token);
          tokenElement.value = data.token;
        } else {
          console.error("Form token element not found.");
        }
      } else {
        console.error(data.error || "Unexpected error occurred.");
      }
    })
    .catch((error) => {
      console.error("AJAX Error:", error.message);
    });
});

// let imagesToLoad = document.querySelectorAll('img[data-src]');
// const loadImages = (image) => {
// 	image.setAttribute('src', image.getAttribute('data-src'));
// 	image.onload = () => {
// 		image.removeAttribute('data-src');
// 	};
// };

// if ('IntersectionObserver' in window) {
// 	const observer = new IntersectionObserver((items, observer) => {
// 		items.forEach((item) => {
// 			if (item.isIntersecting) {
// 				loadImages(item.target);
// 				observer.unobserve(item.target);
// 			}
// 		});
// 	});
// 	imagesToLoad.forEach((img) => {
// 		observer.observe(img);
// 	});
// } else {
// 	imagesToLoad.forEach((img) => {
// 		loadImages(img);
// 	});
// }
