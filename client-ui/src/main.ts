function grocersListSetup() {
  async function getPostGatingOptions(): Promise<void> {
    const grocersList = (window as any).grocersList;
    const ajaxUrl = grocersList?.ajaxUrl;
    const postId = grocersList?.postId;
    const security = grocersList?.nonces?.grocers_list_get_post_gating_options;

    if (!ajaxUrl || !postId || !security) {
      console.warn("Missing required data for post gating options");
      (window as any).grocersList.postGatingConfig = {
        postGated: false,
        recipeCardGated: false,
      };
      return;
    }

    const response = await fetch(ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        action: "public_grocers_list_get_post_gating_options",
        postId: postId.toString(),
        security: security,
      }),
    });

    if (!response.ok) {
      console.warn("Error fetching post gating options:", response.statusText);
      (window as any).grocersList = {
        ...(window as any).grocersList,
        postGatingConfig: {
          postGated: false,
          recipeCardGated: false,
        },
      };
      return;
    }

    const data = await response.json();
    if (data.success) {
      (window as any).grocersList.postGatingConfig = {
        postGated: data.data.postGated,
        recipeCardGated: data.data.recipeCardGated,
      };
    } else {
      console.warn("Failed to fetch post gating options:", data);
      (window as any).grocersList.postGatingConfig = {
        postGated: false,
        recipeCardGated: false,
      };
      return;
    }
  }

  window.addEventListener("load", async () => {
    try {
      // Only fetch post gating options if we have a postId (i.e., we're on a single post)
      const grocersList = (window as any).grocersList;
      if (grocersList?.postId) {
        // This will set the postGatingConfig in the grocersList object
        await getPostGatingOptions();
      }

      grocersList.ready = true; // tells widget setup is complete
    } catch (error) {
      console.error("GrocersList API key validation error:", error);
    }
  });
}

grocersListSetup();
