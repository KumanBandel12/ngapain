// API Configuration
const API_BASE_URL = "http://localhost/backend/api";

// User session management
class UserSession {
  static setUser(userData) {
    localStorage.setItem("user", JSON.stringify(userData))
    localStorage.setItem("user_id", userData.user_id)
    if (userData.theme) {
        localStorage.setItem("theme", userData.theme);
    }
  }

  static getUser() {
    const user = localStorage.getItem("user")
    return user ? JSON.parse(user) : null
  }

  static getUserId() {
    return localStorage.getItem("user_id")
  }

  static clearSession() {
    localStorage.removeItem("user")
    localStorage.removeItem("user_id")
  }

  static isLoggedIn() {
    return this.getUser() !== null && this.getUserId() !== null
  }
}

// API Helper functions
class API {
  static async request(endpoint, options = {}) {
    const url = `${API_BASE_URL}/${endpoint}`
    const config = {
      headers: {
        "Content-Type": "application/json",
        ...options.headers,
      },
      ...options,
    }

    try {
      const response = await fetch(url, config)
      const data = await response.json()

      if (!response.ok) {
        throw new Error(data.message || "API request failed")
      }

      return data
    } catch (error) {
      console.error("API Error:", error)
      throw error
    }
  }

  static async get(endpoint, params = {}) {
    const queryString = new URLSearchParams(params).toString()
    const url = queryString ? `${endpoint}?${queryString}` : endpoint
    return this.request(url)
  }

  static async post(endpoint, data) {
    return this.request(endpoint, {
      method: "POST",
      body: JSON.stringify(data),
    })
  }

  static async put(endpoint, data) {
    return this.request(endpoint, {
      method: "PUT",
      body: JSON.stringify(data),
    })
  }

  static async delete(endpoint, data) {
    return this.request(endpoint, {
      method: "DELETE",
      body: JSON.stringify(data),
    })
  }
}

// Auth API
class AuthAPI {
  static async login(email, password) {
    try {
      return await API.post("auth.php", {
        action: "login",
        email: email,
        password: password,
      })
    } catch (error) {
      console.error("Login API Error:", error)
      throw new Error(`Login failed: ${error.message}`)
    }
  }

  static async register(nama, email, password) {
    try {
      return await API.post("auth.php", {
        action: "register",
        nama: nama,
        email: email,
        password: password,
      })
    } catch (error) {
      console.error("Register API Error:", error)
      throw new Error(`Registration failed: ${error.message}`)
    }
  }

  static async deleteAccount() {
    try {
      const userId = UserSession.getUserId();
      if (!userId) {
        throw new Error("User not logged in");
      }
      return await API.post("auth.php", {
        action: "delete_account",
        user_id: userId,
      });
    } catch (error) {
      console.error("Delete Account API Error:", error);
      throw new Error(`Failed to delete account: ${error.message}`);
    }
  }

  static async logout() {
    try {
      return await API.post("auth.php", {
        action: "logout"
      });
    } catch (error) {
      console.error("Logout API Error:", error);
      throw new Error(`Logout failed: ${error.message}`);
    }
  }

  static async changePassword(currentPassword, newPassword) {
    try {
        const userId = UserSession.getUserId();
        if (!userId) {
            throw new Error("User not logged in");
        }
        return await API.post("auth.php", {
            action: "change_password",
            user_id: userId,
            current_password: currentPassword,
            new_password: newPassword,
        });
    } catch (error) {
        console.error("Change Password API Error:", error);
        throw new Error(`Gagal mengganti kata sandi: ${error.message}`);
    }
  }
}

// Tujuan API
class TujuanAPI {
  static async getAll(searchTerm = "") {
    try {
      const userId = UserSession.getUserId();
    const params = { user_id: userId };
    if (searchTerm) {
      params.search = searchTerm;
    }
    return API.get("tujuan.php", params)
    } catch (error) {
      console.error("Get Tujuan API Error:", error)
      throw new Error(`Failed to load goals: ${error.message}`)
    }
  }

  static async create(tujuanData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.post("tujuan.php", { ...tujuanData, user_id: userId })
    } catch (error) {
      console.error("Create Tujuan API Error:", error)
      throw new Error(`Failed to create goal: ${error.message}`)
    }
  }

  static async update(tujuanData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.put("tujuan.php", { ...tujuanData, user_id: userId })
    } catch (error) {
      console.error("Update Tujuan API Error:", error)
      throw new Error(`Failed to update goal: ${error.message}`)
    }
  }

  static async delete(tujuanId) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.delete("tujuan.php", { id: tujuanId, user_id: userId })
    } catch (error) {
      console.error("Delete Tujuan API Error:", error)
      throw new Error(`Failed to delete goal: ${error.message}`)
    }
  }
}

// Tugas API
class TugasAPI {
  static async getAll(searchTerm = "", sortBy = 'date_start', sortOrder = 'DESC', excludeOverdue = false) {
    try {
      const userId = UserSession.getUserId();
      const params = { 
          user_id: userId,
          sortBy: sortBy,
          sortOrder: sortOrder,
          exclude_overdue: excludeOverdue // Mengirim parameter ini ke backend
      };
      if (searchTerm) {
        params.search = searchTerm;
      }
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.get("tugas.php", params)
    } catch (error) {
      console.error("Get Tugas API Error:", error)
      throw new Error(`Failed to load tasks: ${error.message}`)
    }
  }

  static async create(tugasData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.post("tugas.php", { ...tugasData, user_id: userId })
    } catch (error) {
      console.error("Create Tugas API Error:", error)
      throw new Error(`Failed to create task: ${error.message}`)
    }
  }

  static async update(tugasData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.put("tugas.php", { ...tugasData, user_id: userId })
    } catch (error) {
      console.error("Update Tugas API Error:", error)
      throw new Error(`Failed to update task: ${error.message}`)
    }
  }

  static async delete(tugasId) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.delete("tugas.php", { id: tugasId, user_id: userId })
    } catch (error) {
      console.error("Delete Tugas API Error:", error)
      throw new Error(`Failed to delete task: ${error.message}`)
    }
  }
}

// Catatan API
class CatatanAPI {
  static async getAll(searchTerm = "") {
    try {
      const userId = UserSession.getUserId();
      const params = { user_id: userId };
      if (searchTerm) {
        params.search = searchTerm;
      }
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.get("catatan.php", params)
    } catch (error) {
      console.error("Get Catatan API Error:", error)
      throw new Error(`Failed to load notes: ${error.message}`)
    }
  }

  static async create(catatanData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.post("catatan.php", { ...catatanData, user_id: userId })
    } catch (error) {
      console.error("Create Catatan API Error:", error)
      throw new Error(`Failed to create note: ${error.message}`)
    }
  }

  static async update(catatanData) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.put("catatan.php", { ...catatanData, user_id: userId })
    } catch (error) {
      console.error("Update Catatan API Error:", error)
      throw new Error(`Failed to update note: ${error.message}`)
    }
  }

  static async delete(catatanId) {
    try {
      const userId = UserSession.getUserId()
      if (!userId) {
        throw new Error("User not logged in")
      }
      return await API.delete("catatan.php", { id: catatanId, user_id: userId })
    } catch (error) {
      console.error("Delete Catatan API Error:", error)
      throw new Error(`Failed to delete note: ${error.message}`)
    }
  }
}

// Sampah API
class SampahAPI {
    /**
    * Mengambil semua item dari sampah untuk pengguna yang sedang login.
    * @returns {Promise<Array>} Promise yang resolve dengan array item sampah.
    */
    static async getAll(searchTerm = "") {
      const userId = UserSession.getUserId();
      if (!userId) {
        throw new Error("User ID not found");
      }
      const params = { user_id: userId };
      if (searchTerm) {
          params.search = searchTerm;
      }
      if (!userId) {
          throw new Error("User ID not found");
      }
      const response = await API.get("sampah.php", params);
      return response.success && Array.isArray(response.data) ? response.data : [];
    }

    /**
    * Memulihkan item sampah kembali ke lokasi aslinya.
    * @param {string|number} sampahId - ID item sampah yang akan dipulihkan.
    * @returns {Promise<Object>} Promise yang resolve dengan respons dari API.
    */
    static async restore(sampahId) {
        const userId = UserSession.getUserId();
        return API.post("sampah.php", {
            action: "restore",
            id: sampahId,
            user_id: userId,
        });
    }

    /**
    * Menghapus item sampah secara permanen.
    * @param {string|number} sampahId - ID item sampah yang akan dihapus.
    * @returns {Promise<Object>} Promise yang resolve dengan respons dari API.
    */
    static async deletePermanent(sampahId) {
        const userId = UserSession.getUserId();
        return API.request(`sampah.php?id=${sampahId}&user_id=${userId}`, {
            method: 'DELETE'
        });
    }

    /**
    * Mengosongkan semua item dari sampah pengguna.
    * @returns {Promise<Object>} Promise yang resolve dengan respons dari API.
    */
    static async emptyTrash() {
        const userId = UserSession.getUserId();
        return API.post("sampah.php", {
            action: "emptyTrash",
            user_id: userId,
        });
    }

    /**
    * Membersihkan item yang sudah kedaluwarsa dari sampah untuk pengguna saat ini.
    * @returns {Promise<Object>} Promise yang resolve dengan respons dari API.
    */
    static async cleanupExpired() {
        try {
            const userId = UserSession.getUserId();
            if (!userId) {
                return { success: false, message: "User not logged in" };
            }
            return await API.post("sampah.php", {
                action: "cleanupExpired",
                user_id: userId,
            });
        } catch (error) {
            console.error("Cleanup Expired API Error:", error);
            return { success: false, message: error.message };
        }
    }
}

// Notif API
class NotifAPI {
    static async getOverdue() {
        return API.get("notifications.php");
    }
}

// Check authentication on page load
document.addEventListener("DOMContentLoaded", () => {
  // Skip auth check for login and register pages
  const currentPage = window.location.pathname
  if (
    currentPage.includes("login.html") ||
    currentPage.includes("register.html") ||
    currentPage.includes("LandingPage.html") ||
    currentPage.includes("landingPage.html") ||
    currentPage.includes("forgot-password.html") ||
    currentPage.includes("reset-password.html")
  ) {
    return
  }

  // Check if user is logged in
  if (!UserSession.isLoggedIn()) {
    console.log("User not logged in, redirecting to login page")
    // Redirect to login page
    window.location.href = "/frontend/pages/login.html"; 
    return
  }

  // Update user info in dashboard
  const user = UserSession.getUser()
  if (user) {
    console.log("User logged in:", user.nama)
    // Update user name in sidebar
    const userNameElement = document.querySelector(".user-name")
    const userEmailElement = document.querySelector(".user-email")

    if (userNameElement) userNameElement.textContent = user.nama
    if (userEmailElement) userEmailElement.textContent = user.email
  }
})