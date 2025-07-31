async function validateApiKey(): Promise<boolean> {
  try {
    const ajaxUrl = (window as any).grocersList?.ajaxUrl;

    const response = await fetch(ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        action: "public_grocers_list_validate_api_key",
      }),
    });

    if (!response.ok) {
      console.warn("Error fetching grocers list config:", response.statusText);
      return false;
    }

    const {
      success,
      membershipSettings = {},
      membershipsEnabled = false,
      creatorAccountId = "",
      is_valid = false,
    } = await response.json();

    if (success) {
      (window as any).grocersList = {
        // Merge the existing config with the new config
        ...(window as any).grocersList,
        config: {
          membershipSettings,
          membershipsEnabled,
          creatorAccountId,
          apiKeyValid: is_valid,
        },
      };
      return is_valid;
    } else {
      return false;
    }
  } catch (error) {
    console.error("Error fetching grocers list config:", error);
    return false;
  }
}

window.addEventListener("load", async () => {
  try {
    const isValid = await validateApiKey();
    if (isValid) {
      console.info("Grocers List Setup Complete");
    } else {
      console.warn("GrocersList API key validation: Invalid or not configured");
    }
  } catch (error) {
    console.error("GrocersList API key validation error:", error);
  }
});
